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
| `/login/codigo` | Segundo passo: código do aplicativo autenticador ou de recuperação (5 min, 5 tentativas) |
| `/conta` | Minha conta: ativar o 2FA, novos códigos de recuperação, trocar a senha |
| `/empresas` | Lista com busca (nome, CPF/CNPJ, domínio), filtro de status, plano e vencimento |
| `/empresas/nova` | Cria a empresa (provisionamento), com plano opcional, e mostra a senha do admin **uma vez** |
| `/empresas/<id>` | Status, plano e vencimento, consumo, domínios, usuários, backups e histórico |
| — suspender / reativar | Suspensa, os endereços da empresa respondem 404; os dados ficam |
| — cancelar | Como suspender, mas é o passo antes da exclusão; grava `cancelado_em` |
| — excluir definitivamente | Só cancelada; pede o endereço digitado e a senha; gera o backup final antes |
| — backup | Gera agora um .zip com banco, uploads, logo e fotos de perfil |
| — domínio próprio | Cadastra e verifica pelo TXT no DNS |
| — nova senha | Para qualquer usuário da empresa; encerra as sessões e tokens dele |
| `/planos` | Planos: nome, preço mensal ou cortesia, limites; desativar tira de novas empresas |
| `/backups` | Todos os backups, inclusive o final das empresas excluídas; download com auditoria |
| `/contas` | Contas do painel: criar, desativar, reativar, nova senha, redefinir 2FA |
| `/auditoria` | Trilha de todas as ações do painel (`plataforma_auditoria`) |

**2FA obrigatório.** A conta do painel que ainda não ativou o 2FA entra só até
`/conta`; o resto redireciona para lá (`Plataforma_Controller::exigirLogin()`).
Mesmo desenho do 2FA dos usuários das empresas: segredo cifrado com a
`encryption_key`, código do aplicativo de uso único (`totp_ultimo_passo`, com
`UPDATE` condicional contra requisições simultâneas) e 10 códigos de
recuperação guardados como SHA-256 (`plataforma_codigos_recuperacao`). Não há
"desativar": perdido o celular e os códigos, outra conta redefine em `/contas`,
ou no servidor:

```bash
php index.php tools plataforma_2fa "email"   # @ como %40
```

**Ações que entregam acesso** (criar conta, nova senha, redefinir 2FA, excluir
empresa) pedem a senha de quem faz, com limite de tentativas (escopo
`confirmacao`). A última conta ativa não pode ser desativada, e ninguém mexe na
própria conta por `/contas`.

**Planos** (`planos`, migration `20261108000100`) guardam preço e limites
(`limite_usuarios`, `limite_os_mes`, `limite_armazenamento_mb`; vazio = sem
limite) e nascem com o plano **Cortesia**. A empresa guarda `plano_id` e o
vencimento em `data_expiracao`. A tela da empresa compara o consumo atual com os
limites, mas **nada é bloqueado ainda**: cobrança, bloqueio por vencimento e por
limite são da Fase 5, com a integração de pagamento.

**Backup e exclusão.** `Tenant_manager::backup()` despeja o schema em SQL linha a
linha (sem carregar a tabela na memória; sessões, tentativas de login e tokens
só com a estrutura), e junta os uploads (`STORAGE_PATH/<schema>/`), a logo e as
fotos de perfil citadas no banco da empresa, mais um `LEIA-ME.txt` com a
restauração. Os arquivos ficam em `SAAS_BACKUP_PATH` ou `STORAGE_PATH/_backups`,
com `.htaccess` negando o acesso; só saem por `/backups/<arquivo>`, com login.
`excluirTenant()` só aceita empresa cancelada e gera o backup final antes de
apagar schema, uploads, logo, fotos, consumo e registro (os domínios vão junto,
a auditoria fica). Se o backup falhar, nada é apagado. O backup final também
tem dados pessoais: defina um prazo de guarda e apague-o depois.

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
pelo banco dela. O backup e a exclusão de uma empresa levam os dela, achados por
`emitente.url_logo` e `usuarios.url_image_user` do schema.

**Ao converter uma instalação única em tenant**, mova o conteúdo de
`STORAGE_PATH` para `STORAGE_PATH/<schema>/`, mantendo as subpastas.

## Configurações por empresa

Na instalação única, a tela Configurações grava parte dos campos no
`application/.env`. Numa empresa do SaaS o `.env` é da plataforma, então:

| Campos | Numa empresa |
| --- | --- |
| Gateways de pagamento (Efí, Mercado Pago, Asaas) | **Da empresa**, no banco dela; credenciais **nunca herdam** as da plataforma (as cobranças cairiam na conta de outro) |
| Imprimir anexos, API ligada, validade do token | Da empresa; até ela mudar, vale o padrão do `.env` |
| SMTP (aba E-mail) e "gerar chave nova para os tokens" | Só da plataforma: a aba e o campo somem |
| Atualizar o Map-OS pelo GitHub | 404: o código é um só, quem atualiza é o deploy da plataforma |

O que a empresa grava vai numa linha de `configuracoes` (`config =
tenant_env`), um JSON com as chaves de `TENANT_ENV_CHAVES`
(`helpers/tenant_helper.php`). Segredos vão cifrados (AES-256-GCM, chave
derivada do `APP_ENCRYPTION_KEY`) e nunca voltam para a tela. O hook
(`tenantCarregarEnv`) lê essa linha a cada requisição da empresa, apaga do
`$_ENV` as credenciais de pagamento herdadas e aplica só as chaves da lista. Uma
chave fora da lista no JSON é ignorada: a empresa não troca banco, SMTP nem
chaves da plataforma. **Não troque o `APP_ENCRYPTION_KEY`** com empresas
configuradas: os segredos delas deixam de abrir e ficam vazios (aviso no log).

## Tarefas agendadas por empresa

A fila de e-mails (`email_queue`) fica no banco de cada empresa. Na linha de
comando não há Host, então o cron da instalação única (`php index.php email
process`) só esvaziaria a fila do banco padrão. Com multi-tenancy, o cron chama:

```cron
*/2 * * * * php /caminho/index.php tools tenants_tarefa email process >> /var/log/mapos-cron.log 2>&1
*/5 * * * * php /caminho/index.php tools tenants_tarefa email retry   >> /var/log/mapos-cron.log 2>&1
```

- `tenants_tarefa` roda a tarefa para cada empresa **ativa**, uma por vez, cada
  uma num **processo próprio** (`Tenant_manager::executarTarefa`, limite de 5
  minutos). Nada de uma empresa (conexão, singletons do CI, globais) chega na
  seguinte.
- O processo filho recebe `MAPOS_TENANT_SCHEMA`; o hook (`tenantResolverCli`)
  aponta banco, uploads e chave dos tokens para a empresa. Schema que não é de
  empresa ativa encerra o processo (código 3; mestre fora do ar, 4) em vez de
  rodar no banco padrão.
- Só tarefas de `Tenant_manager::TAREFAS_POR_TENANT` (`email/process`,
  `email/retry`). Uma trava (`flock`) impede duas execuções da mesma tarefa ao
  mesmo tempo. Falha de uma empresa vai para o log e não para as outras; o
  código de saída é 1 se alguma falhou.
- O filho herda o ambiente inteiro. Passar o ambiente como array ao
  `proc_open` descarta as variáveis vazias: `EMAIL_SMTP_CRYPTO=` virava o
  padrão `tls` no filho.

**Remetente.** Todas as empresas usam o SMTP do `.env`. Mandar "De:
contato@empresa.com" por um servidor que não é do domínio dela falha no SPF e
no DMARC e cai em spam. Com `SAAS_EMAIL_REMETENTE` preenchido, o e-mail de uma
empresa sai como `"Nome da empresa" <SAAS_EMAIL_REMETENTE>`, com Reply-To para o
e-mail dela e o envelope (Return-Path) no domínio da plataforma
(`MY_Email::cabecalhosDaPlataforma`). Vazio, nada muda. SMTP próprio por
empresa fica para depois, se for pedido.

No ambiente local, o Mailpit guarda todo e-mail enviado: http://127.0.0.1:8026.

**Rode o cron com o usuário do servidor web** (por exemplo `www-data`), não
como root. O arquivo de log do dia nasce com o dono de quem escreve primeiro;
criado pelo root, o PHP-FPM não consegue mais escrever nele e os erros das
telas somem do log até o dia seguinte.

## Logs

Todas as empresas escrevem em `application/logs`. `application/core/MY_Log.php`
põe a empresa no começo de cada linha, para saber de qual é um erro (e separar
o que é de uma empresa num pedido de dados):

```
ERROR - 2026-11-07 20:08:57 --> [mapos_loja2_3a09fa] 404 Page Not Found: ...
ERROR - 2026-11-07 20:08:56 --> [plataforma] ...
```

Na instalação única a linha fica como sempre foi.

## Tokens da API por empresa

Todas as empresas usam o mesmo `API_JWT_KEY` do `.env`, e os ids de usuário e
cliente se repetem entre elas (todo schema tem o usuário 1). Sem separação, um
token da loja1 valia na loja2 como o usuário de mesmo id: o `sid` da sessão não
barrava, porque `familiaAtiva()` só recusa sessão revogada, e o token da área do
cliente nem tem `sid`.

`Authorization_Token` assina com `HMAC-SHA256(API_JWT_KEY, schema)` e grava o
schema na claim `ten`; na validação a assinatura e a claim precisam ser da
empresa do Host. Token sem `ten` (da instalação única, ou anterior a esta
mudança) não vale numa empresa. Os refresh tokens já ficavam no banco de cada
empresa. O schema atual vem de `tenantSchemaAtual()` (`helpers/tenant_helper.php`),
o mesmo que separa os uploads.

## Produção

VPS com outro sistema atrás de Traefik: `docs/producao-vps.md` e `docker/producao/`.

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
`provisionar()` só a chama em uma tentativa que falhou. A exclusão definitiva de
uma empresa é `excluirTenant()`, pelo painel (ver "Backup e exclusão" acima):
backup final, schema, pasta de uploads, logo e fotos de perfil.

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
