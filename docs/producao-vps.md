# Produção: Map-OS SaaS numa VPS com outro sistema atrás de Traefik

Guia para a VPS que já roda o ZAPScout (Traefik v3 em Docker nas portas 80/443,
rede `saas_edge`, resolvedor de certificados `le`), com o Map-OS em
`gestordauel.com.br`. O ZAPScout continua igual: o Map-OS é um projeto Docker
separado, com o próprio MySQL, e só se pendura na rede do Traefik.

```
internet ──443──> Traefik (do ZAPScout)
                   ├─ app.gestordauel.com.br, api.gestordauel.com.br ──> ZAPScout
                   └─ painel.gestordauel.com.br, <empresa>.gestordauel.com.br,
                      domínios próprios verificados ──> mapos-nginx ──> PHP-FPM ──> MySQL (do Map-OS)
```

O Traefik aprende os endereços do Map-OS por um arquivo
(`/opt/mapos-dados/traefik/mapos.yml`) que o próprio Map-OS reescreve quando uma
empresa é criada, um domínio próprio é verificado ou uma empresa é excluída. Cada
endereço ganha o seu certificado pelo mesmo desafio HTTP que o Traefik já usa.

Arquivos: `docker/producao/` (compose, Dockerfile, nginx, cron, modelo do `.env`).

## 0. Antes de começar

- **DNS** (hPanel da Hostinger → Domínios → gestordauel.com.br → DNS): um
  registro **A** com nome `*` apontando para o IP da VPS. Os registros que já
  existem (`app`, `api`) continuam; o curinga só vale para nomes sem registro
  próprio. Confira de fora: `nslookup qualquercoisa.gestordauel.com.br`.
- **Firewall**: só 22, 80 e 443 abertos (`sudo ufw status`). O MySQL do Map-OS
  não publica porta.
- **SMTP** da plataforma para o remetente `nao-responda@gestordauel.com.br`, com
  SPF e DKIM do domínio configurados no provedor de e-mail.
- **Backup da VPS** ligado no hPanel (snapshots), enquanto o backup externo não
  existe (`RETOMADA_FASE1.md`, "Backup: o que falta").

## 1. Código e pastas

```bash
sudo mkdir -p /opt/mapos /opt/mapos-dados/storage /opt/mapos-dados/backups /opt/mapos-dados/traefik
sudo chown -R "$USER": /opt/mapos
git clone -b fase-1-multi-tenancy https://github.com/malchrDev/mapos.git /opt/mapos
cd /opt/mapos

# Dependências PHP (não vão no git), pelo container oficial do Composer:
docker run --rm -u "$(id -u):$(id -g)" -v /opt/mapos:/app -w /app composer:2 \
  install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs
```

Depois do merge do PR, troque `fase-1-multi-tenancy` por `master`.

## 2. Configuração

```bash
cd /opt/mapos/docker/producao
cp env.exemplo .env
chmod 600 .env
openssl rand -base64 24   # MYSQL_ROOT_PASSWORD
openssl rand -base64 24   # DB_PASSWORD
openssl rand -base64 32   # APP_ENCRYPTION_KEY
openssl rand -base64 48   # API_JWT_KEY
nano .env                 # cole as chaves e preencha o SMTP
```

- **Nunca troque o `APP_ENCRYPTION_KEY` depois:** ele cifra o 2FA e as
  credenciais de pagamento das empresas.
- `SAAS_SUBDOMINIOS_RESERVADOS=app,api` impede que uma empresa pegue os
  endereços do ZAPScout. Acrescente ali todo nome novo que o ZAPScout passar a usar.
- O `.env` fica fora do git (`.gitignore`).

## 3. Subir o Map-OS

```bash
cd /opt/mapos/docker/producao
docker compose up -d --build --wait
docker compose logs cron | head    # "Banco mestre atualizado." e "Rotas gravadas em /traefik/mapos.yml."
```

O container `cron` cria as tabelas do banco mestre e regrava as rotas a cada
subida, e depois roda a fila de e-mails de cada empresa (a cada 2 e 5 minutos)
e o backup diário (às 3h).

Conta do painel (a senha aparece uma vez):

```bash
docker compose exec -u www-data web php index.php tools plataforma_conta admin%40gestordauel.com.br "Seu Nome"
```

Comandos `tools` sempre com `-u www-data`: rodando como root, o arquivo de log do
dia passa a ser do root e o PHP-FPM não consegue mais escrever nele.

## 4. Ligar o Traefik às rotas do Map-OS (mexe no ZAPScout)

É a única mudança no projeto do ZAPScout: o Traefik passa a ler também a pasta de
rotas do Map-OS, somente leitura. O Traefik é recriado uma vez e o ZAPScout fica
fora do ar por alguns segundos: faça num horário de pouco uso.

```bash
cd /opt/saas
cp docker-compose.yml docker-compose.yml.antes-do-mapos   # cópia para desfazer
nano docker-compose.yml
```

No serviço `traefik`, acrescente às linhas de `command:` (mesmo formato das que
já estão lá, como `--providers.docker=true`):

```yaml
      - "--providers.file.directory=/etc/traefik/mapos"
      - "--providers.file.watch=true"
```

e em `volumes:` do mesmo serviço:

```yaml
      - "/opt/mapos-dados/traefik:/etc/traefik/mapos:ro"
```

Aplique e confira:

```bash
docker compose up -d traefik
docker logs traefik --since 2m 2>&1 | grep -i -E "error|mapos" | head
curl -sI https://app.gestordauel.com.br | head -1      # ZAPScout continua respondendo
```

Para desfazer: `cp docker-compose.yml.antes-do-mapos docker-compose.yml && docker compose up -d traefik`.

## 5. Primeiro acesso

1. Abra https://painel.gestordauel.com.br. O primeiro acesso a cada endereço
   leva alguns segundos (o Traefik emite o certificado na hora).
2. Entre com a conta do passo 3 e ative o 2FA (é obrigatório).
3. Empresas → Nova empresa. A empresa abre em `https://<endereço>.gestordauel.com.br`.

Se um endereço der erro de certificado, veja `docker logs traefik 2>&1 | grep -i acme`:
o DNS precisa apontar para a VPS antes da criação.

## 6. Domínio próprio de um cliente

1. Painel → empresa → Domínios → adicionar `www.cliente.com.br`.
2. O cliente cria no DNS dele:
   - o **TXT** mostrado no painel;
   - um **CNAME** de `www.cliente.com.br` para `<endereço>.gestordauel.com.br`, ou um **A** para o IP da VPS.
3. Painel → Verificar. A rota é criada e o certificado sai no primeiro acesso.

## 7. Atualizar o Map-OS

```bash
cd /opt/mapos && git pull
docker run --rm -u "$(id -u):$(id -g)" -v /opt/mapos:/app -w /app composer:2 \
  install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs
cd docker/producao
docker compose up -d --build --wait                    # o cron migra o banco mestre e regrava as rotas
docker compose exec -u www-data web php index.php tools tenants_migrate   # migrations de cada empresa
```

O botão "Atualizar o Map-OS" não existe dentro das empresas: o código é um só e
quem atualiza é este procedimento.

## 8. Operação

| O quê | Onde |
| --- | --- |
| Logs da aplicação (com a empresa em cada linha) | `docker compose exec web sh -c 'tail -50 $(ls -t /app/application/logs/log-*.php \| head -1)'` |
| Saída das tarefas | `docker compose logs -f cron` |
| Backups das empresas | `/opt/mapos-dados/backups` (diário às 3h, 14 dias; os 3 mais novos ficam sempre) e Painel → Backups |
| Uploads das empresas | `/opt/mapos-dados/storage/<schema>/` |
| Rotas do Traefik | `/opt/mapos-dados/traefik/mapos.yml` (regerar: `tools traefik_rotas`) |
| Banco | `docker compose exec mysql mysql -uroot -p` |

Copie `/opt/mapos-dados/backups` para fora da VPS regularmente (por exemplo,
`rsync` para outra máquina) até o backup externo automático existir.

## Limites conhecidos

- O Let's Encrypt emite até 50 certificados novos por semana para
  `gestordauel.com.br` (um por empresa nova). Acima disso, troque para um
  certificado curinga por desafio DNS.
- Cobrança, inscrição pública e e-mail de boas-vindas ainda não existem: a empresa
  é criada no painel e a senha é entregue à mão (Fase 5).
