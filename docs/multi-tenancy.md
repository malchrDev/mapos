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
| `SAAS_PAINEL_HOST` | painel administrativo da plataforma, no banco mestre (ver abaixo) |
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
php index.php tools plataforma_conta "voce%40empresa.com" "Seu%20Nome"   # conta do painel da plataforma
php index.php tools plataforma_senha "voce%40empresa.com"               # nova senha para essa conta
```

Depois de cada deploy: `tenant_mestre` e `tenants_migrate`. A primeira conta do
painel da plataforma só nasce pelo CLI (`plataforma_conta`); o resto se faz pelo
painel.

## Painel administrativo da plataforma

No hostname `SAAS_PAINEL_HOST` o hook aponta a conexão para o **banco mestre** e
marca o contexto `plataforma`. `config/routes.php` então troca a tabela de rotas
inteira pela de `config/routes_plataforma.php`: só os controllers
`Plataforma_*` respondem, e qualquer outra URI (telas de tenant, API, `tools`)
é 404. Os controllers `Plataforma_*` também se recusam a rodar fora desse
hostname (`Plataforma_Controller`), mesmo chamados pelo nome.

| Tela | O que faz |
| --- | --- |
| `/login` | Entrada (superfície escura do DESIGN.md), com `Limite_login` no escopo `plataforma` |
| `/empresas` | Lista com busca (nome, CPF/CNPJ, domínio) e filtro de status |
| `/empresas/nova` | Cria a empresa (provisionamento) e mostra a senha do admin **uma vez** |
| `/empresas/<id>` | Status, domínios (com o TXT de verificação), usuários e histórico |
| — suspender / reativar | Suspensa, os endereços da empresa respondem 404; os dados ficam |
| — domínio próprio | Cadastra e verifica pelo TXT no DNS |
| — nova senha | Para qualquer usuário da empresa; encerra as sessões e tokens dele |
| `/auditoria` | Trilha de todas as ações do painel (`plataforma_auditoria`) |

Contas do painel (`plataforma_usuarios`) e usuários das empresas não se
misturam: um admin de empresa não entra no painel, e uma conta do painel não
entra numa empresa. A conta é relida a cada requisição: desativada ou com a
senha trocada, a sessão aberta antes cai. Senhas geradas só aparecem na
resposta do próprio POST (nunca em flashdata), e as telas do painel vão com
`Cache-Control: no-store`.

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
4. **`carregarBancoSql()`** — carrega o `banco.sql` como o instalador faz
   (`instaladorMontarSql()` troca os marcadores do admin pelo e-mail do pedido e
   uma **senha aleatória**): esquema, configurações padrão e perfil
   Administrador. O endereço e o documento de exemplo do admin são apagados.
5. **`migrarSchema()`** — aplica as migrations posteriores à versão que o
   `banco.sql` grava, numa conexão própria do schema novo.
   `$CI->db`/`$CI->dbforge` são trocados durante a execução e devolvidos no fim.
6. **INSERT em `tenants` e `tenant_dominios`** no mestre, numa transação.

**Por que `banco.sql` e não todas as migrations desde 2012:** o histórico antigo
de migrations do Map-OS diverge do `banco.sql` — rodado do zero, ele deixa
`cobrancas.charge_id` como `int` (os gateways gravam texto ali), `vendas.garantia`
como `varchar`, e duas migrations de 2021–2022 falham em silêncio. Carregando o
`banco.sql`, o banco de cada empresa sai **idêntico** ao de uma instalação nova
(conferido coluna a coluna e índice a índice), que é o que o resto do sistema
espera. `TenantIsolamentoTest::testEsquemaDoTenantEOMesmoDeUmaInstalacaoNova`
guarda as colunas que divergiam.

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

## Uploads por empresa

Os anexos de OS, os arquivos da tela **Arquivos** e o log de CSP ficam em
`STORAGE_PATH/<schema>/` (ver `docs/armazenamento-de-arquivos.md`). O hook grava
o schema em `$GLOBALS['mapos_tenant_schema']` e `armazenamentoRaiz()` devolve a
subpasta dele. Os caminhos no banco continuam relativos (`anexos/11-2026/OS-1`),
então nenhum controller mudou, e o mesmo caminho em outra empresa aponta para a
pasta dela. Subir de pasta (`../outro_schema/...`) ou usar um caminho absoluto
para a pasta de outra empresa é recusado por `armazenamentoLocalizar()`.

- `migrarSchema()` ativa o schema do tenant enquanto roda as migrations (a
  `move_uploads_to_storage` mexe em arquivos) e devolve o anterior no fim.
- `Tenant_manager::pastaArmazenamento($schema)` dá a pasta de uma empresa, para
  backup e exclusão.
- Um valor de schema fora de `[a-z0-9_]{1,64}` derruba a requisição em vez de
  cair na pasta comum.
- A pasta nasce no primeiro upload e recebe o próprio `.htaccess` que nega o
  acesso web.

A logo do emitente e as fotos de perfil continuam em `assets/uploads` e
`assets/userImage`, numa pasta comum a todas as empresas. São públicas por
desenho e têm nome aleatório; cada empresa só aponta para os próprios arquivos
pelo banco dela. Na exclusão de uma empresa (Fase 6) eles precisam ser apagados
a partir de `emitente.url_logo` e `usuarios.url_image_user` do schema.

**Ao converter uma instalação única em tenant**, mova o conteúdo de
`STORAGE_PATH` para `STORAGE_PATH/<schema>/`, mantendo as subpastas.

## Ambiente local (Docker)

`docker/teste/` sobe MySQL 8.4, PHP-FPM 8.5 e nginx em `127.0.0.1:8090`. Os
navegadores resolvem qualquer `*.localhost` para a própria máquina, então cada
tenant tem o seu endereço sem mexer no arquivo `hosts`.

```bash
docker compose -f docker/teste/docker-compose.yml up -d --build --wait
docker compose -f docker/teste/docker-compose.yml exec web php index.php tools tenant_mestre
docker compose -f docker/teste/docker-compose.yml exec web php index.php tools tenant_criar loja1.localhost "Loja%201" "admin%40loja1.com"
# abrir http://loja1.localhost:8090 e entrar com o e-mail e a senha mostrados

docker compose -f docker/teste/docker-compose.yml exec web php index.php tools plataforma_conta "voce%40empresa.com" "Seu%20Nome"
# abrir http://painel.localhost:8090 — painel da plataforma
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
tenant em produção é da Fase 6 (backup por tenant). Ela também precisa apagar
`pastaArmazenamento($schema)` e a logo e as fotos de perfil da empresa (ver
"Uploads por empresa").

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
