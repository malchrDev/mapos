# Changelog — MAPOS SaaS (SLA + ITIL + ISO 20000/27001)

Registro das modificações promovidas sobre o MAPOS v5.3.0.
Base upstream: https://github.com/ramonsilva20/mapos
Fork: https://github.com/malchrDev/mapos

Formato baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) e
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Não publicado]

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
