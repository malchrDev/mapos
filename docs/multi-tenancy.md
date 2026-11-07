# Multi-tenancy (Fase 1)

Cada cliente SaaS (tenant) tem o **seu próprio schema MySQL** dentro do mesmo
cluster. Não existe coluna `tenant_id` nas tabelas de negócio: o schema é a
fronteira, e toda linha dentro de `mapos_<dominio>_<sufixo>` pertence àquele
tenant.

## Como funciona

```
Requisição → hostname (HTTP_HOST)
  → hook pre_system (application/hooks/tenant.php)
    → SELECT em tenant_dominios + tenants no banco MESTRE
      → $_ENV['DB_DATABASE'] = schema do tenant; base_url = hostname pedido
        → CodeIgniter carrega config/database.php já no schema certo
```

O hook roda **antes** de o framework abrir a conexão. Nenhum model, controller ou
view sabe que multi-tenancy existe: a conexão já nasce apontando para o schema do
tenant.

## Configuração (`application/.env`)

| Variável | O que é |
| --- | --- |
| `DB_MASTER_DATABASE` | Banco de `tenants`, `tenant_dominios` e `tenant_usage`. **Diferente dos schemas dos tenants.** Vazio desliga a multi-tenancy. |
| `DB_DATABASE` | Banco de trabalho da instalação única; com multi-tenancy, só o CLI o usa. |
| `SAAS_DOMINIO_BASE` | Domínio dos subdomínios de plataforma (ex.: `seusaas.com.br` → `loja.seusaas.com.br`). |
| `SAAS_PAINEL_HOST` | Hostname reservado do painel administrativo da plataforma (ex.: `painel.seusaas.com.br`). |

`DB_MASTER_DATABASE` vazio desliga o hook: o sistema funciona como o Map-OS de
sempre, em instalação única. É o padrão, e assim continua.

`APP_COOKIE_DOMAIN` precisa ficar **vazio**: o cookie fica preso ao hostname
pedido, e a sessão de um tenant não chega a outro. (Mesmo que chegasse, a tabela
`ci_sessions` é de cada schema — o ID de sessão de um tenant não existe no outro.)

### Usuário do MySQL

O usuário da aplicação precisa criar e dropar os schemas dos tenants. Basta o
prefixo, nada além:

```sql
GRANT ALL PRIVILEGES ON `mapos\_%`.* TO 'mapos'@'%';
```

## Resolução (hook) — falha fechada

| Host da requisição | Resposta |
| --- | --- |
| domínio verificado de tenant `ativo` | segue para o Map-OS no schema do tenant |
| `SAAS_PAINEL_HOST` | 503 (o painel ainda não existe; o hostname já é reservado) |
| desconhecido, inválido, de tenant suspenso/cancelado, domínio próprio não verificado | **404** |
| banco mestre fora do ar | **503**, e a falha vai para o `error_log` do PHP |

Com a multi-tenancy ligada, nenhuma requisição web cai no `DB_DATABASE`: isso
serviria o mesmo banco a qualquer hostname. O hostname é validado com
`FILTER_VALIDATE_DOMAIN` antes de qualquer consulta, e a consulta ao mestre usa
statement parametrizado (PDO).

O `base_url` do tenant é montado a partir do Host pedido (`tenantBaseUrl()`), que
a essa altura já foi conferido contra `tenant_dominios` — não é cabeçalho
arbitrário. HTTPS só quando o próprio servidor informa (`$_SERVER['HTTPS']`);
atrás de proxy TLS, o servidor web precisa repassar isso ao PHP.

## Domínios

Um tenant tem vários domínios em `tenant_dominios`:

- **plataforma** — subdomínio de `SAAS_DOMINIO_BASE`, criado no provisionamento e
  já verificado;
- **proprio** — domínio do cliente. Só resolve depois de verificado: o cliente
  publica um TXT `_mapos-verificacao.<dominio>` com `mapos-verificacao=<token>`.
  Sem isso, um tenant poderia cadastrar o domínio de outra empresa e receber o
  tráfego dela.

Um hostname pertence a um tenant só (`UNIQUE`), e `SAAS_DOMINIO_BASE` e
`SAAS_PAINEL_HOST` nunca podem ser de tenant.

HTTPS em produção: certificado curinga para `*.SAAS_DOMINIO_BASE` e emissão
automática (ACME) para domínios próprios verificados — fica com o servidor web
(Caddy/Traefik), não com o Map-OS.

## Comandos (CLI)

Os argumentos viram segmentos de URL no CodeIgniter, que recusa espaço e `@`:
use `%20` e `%40`.

```bash
php index.php tools tenant_mestre                    # cria/atualiza as tabelas do mestre
php index.php tools tenant_criar loja.seusaas.com.br "Loja%20Exemplo" "dono%40loja.com"
php index.php tools tenant_dominio loja.seusaas.com.br www.loja.com.br   # mostra o TXT
php index.php tools tenant_verificar www.loja.com.br                    # confere o TXT
php index.php tools tenants_migrate                  # leva todos os tenants à última migration
```

Depois de cada deploy: `tenant_mestre` e `tenants_migrate`. Enquanto o painel
administrativo não existe, o provisionamento é por aqui.

## Provisionamento

`Tenant_manager::provisionar()` (application/libraries/Tenant_manager.php), nesta
ordem:

1. Recusa domínio inválido, fora de `SAAS_DOMINIO_BASE`, reservado, ou já
   registrado **em qualquer status** (suspenso e cancelado continuam reservados);
   recusa e-mail de admin inválido.
2. **`gerarNomeSchema()`** — `mapos_<dominio sem pontuação>_<6 hex aleatórios>`.
   O sufixo impede que `a-b.com` e `ab.com` caiam no mesmo schema.
3. **`criarSchema()`** — `CREATE DATABASE`. Schema que já existe é **erro**, nunca
   reaproveitado: reaproveitar entregaria a um tenant os dados de outro.
4. **`migrarSchema()`** — roda as migrations de negócio numa conexão própria do
   schema novo. `$CI->db`/`$CI->dbforge` são trocados durante a execução e
   devolvidos no fim.
5. **Dados iniciais** — as configurações padrão e o perfil Administrador de uma
   instalação nova (`application/config/tenant_dados_iniciais.php`, conferido
   contra `banco.sql` por `TenantDadosIniciaisTest`) e o usuário admin com o
   e-mail do pedido e uma **senha aleatória**.
6. **INSERT em `tenants` e `tenant_dominios`** no mestre, numa transação.

A ordem é do mais barato de desfazer para o mais caro. Se qualquer passo depois
do `CREATE DATABASE` falhar, o schema é dropado: sem registro no mestre nenhuma
requisição o resolve.

A senha inicial só existe no retorno de `provisionar()`. Hoje o CLI a mostra na
tela; na Fase 5 ela é trocada por um e-mail de boas-vindas com link para definir
a senha, enviado ao e-mail do pedido de inscrição depois da confirmação do
pagamento — ou na hora, no plano CORTESIA.

O `Tenant_manager` abre as próprias conexões (uma com o mestre, uma por schema),
com as credenciais da conexão da aplicação e `db_debug` desligado — os erros
viram exceção, para que o provisionamento consiga desfazer o que já fez.

### Migrations do mestre ficam em outra pasta

`application/database/migrations_mestre/` só roda no `DB_MASTER_DATABASE`.
`application/database/migrations/` são as de negócio: rodam em cada tenant e na
instalação única. Separadas, nenhuma migration do mestre entra num tenant, e a
verificação de "banco desatualizado" do painel (que compara a pasta com a versão
do banco) continua correta em cada tenant.

## Ambiente local (Docker)

`docker/teste/` sobe MySQL 8.4, PHP-FPM 8.5 e nginx em `127.0.0.1:8090`. Os
navegadores resolvem qualquer `*.localhost` para a própria máquina, então cada
tenant tem o seu endereço sem mexer no arquivo `hosts`.

```bash
docker compose -f docker/teste/docker-compose.yml up -d --build --wait
docker compose -f docker/teste/docker-compose.yml exec web php index.php tools tenant_mestre
docker compose -f docker/teste/docker-compose.yml exec web php index.php tools tenant_criar loja1.localhost "Loja%201" "admin%40loja1.com"
# abrir http://loja1.localhost:8090 e entrar com o e-mail e a senha mostrados
```

Os dados ficam num volume (`down` mantém, `down -v` apaga). O MySQL desse
ambiente roda sem fsync por transação, para provisionar mais rápido; nunca use
essas opções em produção.

## Testes de integração (MySQL real)

```bash
docker compose -f docker/teste/docker-compose.yml run --rm testes
```

Usam um MySQL próprio, em memória, e não tocam nos tenants do ambiente local.
`tests/Integration/TenantIsolamentoTest.php` provisiona tenants de verdade e
confere: cada Host só enxerga o próprio schema; admin e dados iniciais; domínio
repetido, fora da base ou reservado é recusado; schema existente é recusado;
falha no provisionamento não deixa sobra; tenant suspenso, Host desconhecido e
mestre fora do ar falham fechados; domínio próprio só resolve depois do TXT; a
migration de `tenant_dominios` leva os domínios antigos e o rollback os devolve.
Fora do container (sem `DB_MASTER_DATABASE`) os testes são pulados.

## Exclusão de tenant (LGPD)

`excluirSchema()` faz `DROP DATABASE` do schema inteiro — o direito ao
esquecimento da empresa é uma operação, não uma varredura linha por linha. Apagar
o registro em `tenants` leva os domínios junto (`ON DELETE CASCADE`).
`provisionar()` só a chama em uma tentativa que falhou; a exclusão definitiva de um
tenant em produção é da Fase 6 (backup por tenant).

O nome do schema entra em SQL cru (`CREATE`/`DROP DATABASE` não aceita binding),
então `validarNomeSchema()` só aceita `mapos_` + `[a-z0-9_]{1,50}` e recusa o
`DB_DATABASE` e o `DB_MASTER_DATABASE` configurados. Nada de backtick, espaço,
ponto ou hífen.

## Limites de plano

`tenant_usage` guarda o consumo por tenant, métrica e período (`AAAA-MM`):
`usuarios`, `os_mes`, `armazenamento_mb`. Fica no banco mestre porque o plano
cobra o consumo, e o consumo precisa ser lido **mesmo com o tenant suspenso**.

O limite em si é aplicado na Fase 5 (billing). Na Fase 1 a tabela e o registro
existem; nada ainda os consulta.

## Instalação única continua igual

Sem `DB_MASTER_DATABASE`, sem banco mestre, sem schema por cliente: o hook não faz
nada e o banco é o `DB_DATABASE` de sempre. Nenhuma instalação existente do
Map-OS precisa mudar.
