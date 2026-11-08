# Changelog — MAPOS SaaS (SLA + ITIL + ISO 20000/27001)

Registro das modificações promovidas sobre o MAPOS v5.3.0.
Base upstream: https://github.com/ramonsilva20/mapos
Fork: https://github.com/malchrDev/mapos

Formato baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) e
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Não publicado]

### Added (2026-11-07, produção)
- `docker/producao/` e `docs/producao-vps.md`: Map-OS numa VPS que já roda outro sistema atrás de Traefik.
  - Projeto Docker próprio, com MySQL, PHP-FPM, nginx interno e cron como www-data.
  - Código montado só para leitura.
  - IP real do visitante e HTTPS repassados pelo proxy.
  - Arquivos da raiz (`banco.sql`, `composer.json`, `*.md`) e o instalador bloqueados.
- Rotas do Traefik geradas pelo Map-OS (`SAAS_TRAEFIK_ARQUIVO`, `tools traefik_rotas`): uma rota e um certificado por endereço, atualizadas ao criar empresa, verificar domínio ou excluir empresa.
- Subdomínios reservados (lista fixa + `SAAS_SUBDOMINIOS_RESERVADOS`) e endereço de empresa com um nível só.
- `tools tenants_backup`: backup diário de cada empresa, com poda (`SAAS_BACKUP_RETENCAO_DIAS`, padrão 14; os 3 mais novos ficam).
- Cookie de sessão e de CSRF sempre só do endereço atual no SaaS, mesmo com `APP_COOKIE_DOMAIN` preenchido.
- Cache do HTML Purifier fora de `application/vendor`.

### Added (2026-11-07, logs)
- `application/core/MY_Log.php`: cada linha do log traz a empresa (`[schema]`) ou `[plataforma]`.

### Security (2026-11-07, configurações por empresa)
- Numa empresa, Configurações gravava no `.env` compartilhado: o admin de qualquer loja trocava o SMTP, **as credenciais dos gateways de pagamento de todas as lojas**, ligava/desligava a API e gerava a chave dos tokens da plataforma, além de atualizar o código pelo GitHub. Agora gateways, API e impressão são da empresa (`configuracoes.tenant_env`, segredos cifrados), credenciais de pagamento nunca herdam as da plataforma, SMTP e chave dos tokens somem da tela e a atualização do código dá 404 numa empresa.

### Added (2026-11-07, tarefas por empresa)
- `tools tenants_tarefa email process|retry`: roda a fila de e-mails de cada empresa ativa, cada uma num processo próprio, com trava contra execuções sobrepostas e limite de tempo. Antes, as filas das empresas nunca eram enviadas (o cron só via o banco padrão).
- Hook na linha de comando: `MAPOS_TENANT_SCHEMA` aponta o processo para uma empresa ativa; outra coisa encerra com código 3.
- `SAAS_EMAIL_REMETENTE`: e-mails das empresas saem pelo remetente da plataforma, com o nome da empresa e Reply-To para ela (SPF/DMARC).
- `docker/teste/`: Mailpit (SMTP de teste) em http://127.0.0.1:8026.

### Added (2026-11-07, gestão do painel)
- 2FA obrigatório nas contas do painel da plataforma (migration do mestre `20261108000000`): login em dois passos, ativação em Minha conta, códigos de recuperação, sem "desativar". CLI `tools plataforma_2fa` para redefinir.
- Minha conta: novos códigos de recuperação e troca da própria senha (mínimo 12 caracteres; as outras sessões caem).
- Contas do painel: criar, desativar, reativar, nova senha e redefinir 2FA de outra conta; ações que entregam acesso pedem a senha de quem faz; a última conta ativa não pode ser desativada.
- Planos (migration do mestre `20261108000100`, nasce com "Cortesia"): preço mensal ou cortesia, limites de usuários, OS por mês e armazenamento. A empresa recebe plano e vencimento, e a tela dela mostra o consumo contra os limites. Cobrança e bloqueio ficam para a Fase 5.
- Backup por empresa (.zip com banco, uploads, logo, fotos de perfil e instruções), tela de backups com download auditado. `SAAS_BACKUP_PATH` no `.env`.
- Cancelar empresa e excluir definitivamente (LGPD): só cancelada, com o endereço digitado e a senha, e backup final antes de apagar schema, arquivos e registro.

### Security (2026-11-07, API)
- Token da API preso à empresa: chave de assinatura derivada do `API_JWT_KEY` e do schema, e claim `ten`. Antes, o token do admin ou de um cliente da loja1 valia na loja2 como a conta de mesmo id (reproduzido pelo nginx: `200` com os clientes da loja2; agora `403`).

### Security (2026-11-07, uploads)
- Uploads separados por empresa: anexos de OS, arquivos e o log de CSP ficam em `STORAGE_PATH/<schema>/` (`armazenamentoTenant()`, gravado pelo hook). Antes, todas as empresas gravavam na mesma pasta. Caminho que sobe para a pasta de outra empresa é recusado; schema fora do padrão derruba a requisição.
- `Tenant_manager::migrarSchema()` roda as migrations com a pasta do tenant ativa; `pastaArmazenamento()` dá a pasta de uma empresa.

### Added (2026-11-07 — noite)
- Painel administrativo da plataforma em `SAAS_PAINEL_HOST`: login com `Limite_login` (escopo `plataforma`), empresas (listar, buscar, criar, suspender, reativar), domínios próprios com verificação, nova senha para usuário de empresa e trilha de auditoria. Rotas próprias (`config/routes_plataforma.php`) que tornam qualquer outra URI 404 no painel; controllers `Plataforma_*` dão 404 fora dele.
- Migration do mestre `20261107010000_create_plataforma`: `plataforma_usuarios`, `plataforma_auditoria`, `ci_sessions`, `login_attempts`.
- CLI: `tools plataforma_conta`, `tools plataforma_senha`.

### Fixed (2026-11-07 — noite)
- Provisionamento carrega o `banco.sql` como o instalador e só então aplica as migrations posteriores. Rodando todas as migrations desde 2012, 11 colunas saíam diferentes de uma instalação nova (`cobrancas.charge_id` como `int`, onde os gateways gravam texto) e duas migrations antigas falhavam em silêncio. Agora colunas e índices são idênticos aos de uma instalação nova. Sai `application/config/tenant_dados_iniciais.php`, que ficou redundante.
- `application/.env.example`: o comentário da multi-tenancy tinha parênteses e quebrava o parser de INI do PHP (`CookieSeguroTest`).

### Added (2026-11-07 — tarde)
- `tenant_dominios` (migration do mestre `20261107000000`): vários domínios por tenant — subdomínio de plataforma (verificado no provisionamento) e domínios próprios com verificação por TXT no DNS. `tenants.dominio` migra para a tabela nova; rollback devolve.
- Provisionamento cria o usuário admin com o e-mail do pedido e senha aleatória (em 2026-11-07, noite, passou a carregar o `banco.sql` como o instalador — ver abaixo).
- CLI: `tools tenant_dominio`, `tools tenant_verificar`; `tools tenant_criar` recebe o e-mail do admin.
- `.env`: `SAAS_DOMINIO_BASE`, `SAAS_PAINEL_HOST`.
- `docker/teste/`: ambiente local no navegador (nginx + PHP-FPM 8.5 em `127.0.0.1:8090`, `*.localhost`) e MySQL de testes em memória separado.

### Security (2026-11-07 — tarde)
- Hook falha fechado com multi-tenancy ligada: Host desconhecido, inválido, de tenant suspenso ou domínio próprio não verificado → 404; mestre fora do ar → 503. Antes caía no `DB_DATABASE`.
- `base_url` derivado do Host já conferido contra `tenant_dominios` (antes era o `APP_BASEURL` fixo para todos os tenants).

### Fixed (2026-11-07 — testado contra MySQL real)
- `application/hooks/tenant.php`: coluna `schema` (palavra reservada) entre crases — a consulta falhava em silêncio e todo tenant caía no banco padrão.
- `application/hooks/tenant.php`: `DB_MASTER_DATABASE` ausente não cai mais para `DB_DATABASE`; falha ao consultar o mestre vai para o `error_log`.
- `Tenant_manager`: migrations do tenant rodam de fato no schema novo (antes rodavam no banco padrão e rebaixavam a versão dele).
- `Tenant_manager`: todo acesso a `tenants` usa o banco mestre (antes o registro era gravado no `DB_DATABASE`).
- `Tenant_manager`: nome de schema com sufixo aleatório e `criarSchema()` recusa schema existente — dois domínios não compartilham mais o mesmo schema.
- `Tenant_manager`: domínio já registrado é recusado em qualquer status; conexões próprias com `db_debug` desligado, para o rollback do provisionamento funcionar.

### Changed (2026-11-07)
- Migrations do banco mestre movidas para `application/database/migrations_mestre/` (antes deixavam todo tenant com o banco "desatualizado").

### Added (2026-11-07)
- CLI: `tools tenant_mestre`, `tools tenant_criar`, `tools tenants_migrate`.
- `docker/teste/`: MySQL 8.4 + PHP 8.5 para testes de integração.
- `tests/Integration/TenantIsolamentoTest.php` (10 testes) e `phpunit.integracao.xml`.

### Added
- Documento de planejamento: `ROADMAP_SAAS.md` — mapa de desenvolvimento SaaS por fases (multi-tenancy, SLA, ITIL, ISO 27001/LGPD, billing, operação).
- `docs/multi-tenancy.md` — documentação completa da arquitetura schema-per-tenant (provisionamento, resolução, limites, exclusão LGPD).
- `docs/migracao-4x-para-5.md` — seção 3.4 explicando que instalações únicas não precisam fazer nada.

### Changed
- `application/.env.example`: adicionadas `DB_PORT=3306` e `DB_MASTER_DATABASE=` (banco mestre opcional, vazio por padrão).
- `application/config/database.php`: adicionado `'port' => (int) ($_ENV['DB_PORT'] ?? 3306)`.
- `application/config/hooks.php`: hook `resolverTenant` em `pre_system` (antes de segurança/Whoops).
- `ROADMAP_SAAS.md`: alinhado com decisão **sem `tenant_id` nas 27 tabelas** — o schema é a fronteira.

### Security
- `application/hooks/tenant.php`: hostname validado com `FILTER_VALIDATE_DOMAIN` antes de consultar o banco mestre; statement PDO parametrizado; guard global contra reentrada.
- `application/libraries/Tenant_manager.php`: transação manual (`START TRANSACTION`/`COMMIT`/`ROLLBACK`) em `provisionar()` para evitar conflito com `trans_start`/`trans_complete` do CI.
- `application/libraries/Tenant_manager.php`: validação explícita de nome de schema (`validarNomeSchema`) bloqueando backticks, pontos, espaços, hífens, nomes de banco reservados (`mapos_padrao`, `mapos_master`) — evita injeção em `CREATE`/`DROP DATABASE`.

### Added (infraestrutura de Fase 1)
- `application/database/migrations/20261106000000_create_tenants.php`: tabela `tenants` no banco mestre (domínio → schema, status, limites, expiração).
- `application/database/migrations/20261106000100_create_tenant_usage.php`: tabela `tenant_usage` no banco mestre (consumo por métrica/período).
- `application/hooks/tenant.php`: hook `pre_system` que resolve o tenant pelo hostname e aponta `DB_DATABASE` para o schema dele antes do bootstrap do CI.
- `application/libraries/Tenant_manager.php`: provisionamento completo (cria schema, roda migrations no schema via staging, grava registro no mestre); geração de nome de schema seguro; remoção de schema (LGPD).

### Tests
- `tests/Unit/TenantManagerTest.php`: 21 testes de isolamento — `nomeSchema()` saneia entrada, `validarNomeSchema()` recusa ataques e nomes reservados, schema gerado pelo sistema passa.

### Nenhuma alteração nos models de negócio
Decisão confirmada: **sem `tenant_id` nas 27 tabelas**. O schema é a fronteira; todos os models, controllers e views continuam intocados na Fase 1. O isolamento é testado criando dois schemas e conferindo que nenhum vaza dado.

## Próximas entradas (a preencher)

### [Fase 1] — 2026-11-06
- Multi-tenancy foundation completa: provisionamento, resolução, isolamento, documentação, testes.

### [Fase 2] — <data>
- SLA: prioridade, severidade, níveis, prazos, breach, dashboard.

### [Fase 3] — <data>
- ITIL: Incidentes, Problemas, Mudanças, CAB, base de conhecimento, CMDB leve.
