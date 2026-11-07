# Retomada — Fase 1 (multi-tenancy), 2026-11-06

## Onde paramos

Fundação de multi-tenancy **implementada e testada localmente**. Nada foi commitado
ainda, e nada foi testado contra um MySQL real — só contra o SQLite em memória dos
testes unitários.

## Estado dos arquivos

### Prontos e verificados (PHPStan limpo, testes passando)

| Arquivo | O que é |
| --- | --- |
| `application/hooks/tenant.php` | Hook `pre_system`: resolve tenant por hostname, aponta `DB_DATABASE` |
| `application/libraries/Tenant_manager.php` | Provisionamento, `nomeSchema()`, `validarNomeSchema()`, criar/excluir schema |
| `application/database/migrations/20261106000000_create_tenants.php` | Tabela `tenants` (banco mestre) |
| `application/database/migrations/20261106000100_create_tenant_usage.php` | Tabela `tenant_usage` (banco mestre) |
| `application/config/hooks.php` | Hook registrado |
| `application/config/database.php` | `port` lido do `.env` |
| `application/.env.example` | `DB_PORT`, `DB_MASTER_DATABASE` documentados |
| `tests/Unit/TenantManagerTest.php` | 21 testes de isolamento, todos passando |
| `docs/multi-tenancy.md` | Documentação completa |
| `docs/migracao-4x-para-5.md` | Seção 3.4 (multi-tenancy não exige nada) |
| `ROADMAP_SAAS.md` | Corrigido: sem `tenant_id` nas 27 tabelas |
| `CHANGELOG_SAAS.md` | Atualizado |

### `git status`

Os 4 arquivos com mudança real de conteúdo são `.env.example`, `database.php`,
`hooks.php` e `docs/migracao-4x-para-5.md`. Os demais aparecem modificados só por
causa de fim de linha (LF/CRLF) — o git os neutraliza. **Nada foi perdido**, apesar
do php-cs-fixer ter rodado no repositório inteiro.

## Decisões tomadas (não rediscutir)

1. **Schema per tenant**, não `tenant_id` compartilhado.
2. **Sem coluna `tenant_id` nas 27 tabelas** — o schema é a fronteira. Models de
   negócio não são tocados na Fase 1.
3. **Só multi-tenancy na Fase 1** — SLA (`prioridade`, `severidade`, `sla_nivel_id`)
   é Fase 2.
4. `DB_MASTER_DATABASE` vazio desliga tudo: instalação única continua igual.
5. `mapos_padrao` e `mapos_master` são nomes reservados, recusados por
   `validarNomeSchema()`.

## O que falta na Fase 1 (em ordem)

### 1. Testar provisionamento contra MySQL real — **bloqueador**

Os testes unitários só cobrem `nomeSchema()` e `validarNomeSchema()`.
`provisionar()` em si nunca rodou: precisa de MySQL para `CREATE DATABASE`, para as
migrations e para o registro em `tenants`. Sem isso, a Fase 1 não está aceita.

Falta levantar MySQL (ou o Docker em `docker/`) com:
- um banco mestre (`mapos_master`),
- um usuário com privilégio de `CREATE/DROP DATABASE`,
- `.env` apontando `DB_HOSTNAME`, `DB_USERNAME`, `DB_PASSWORD`,
  `DB_DATABASE=mapos_padrao`, `DB_MASTER_DATABASE=mapos_master`.

Depois provisionar dois tenants, criar um cliente em cada um e conferir que um
não vê o dado do outro — é o critério de aceitação do roadmap.

### 2. Testes de integração de isolamento (2 schemas)

`TenantManagerTest` cobre a estática. Falta o teste de comportamento: dois schemas,
um registro em cada, request com `Host` de cada um, e a garantia de que a listagem
só devolve o registro do próprio tenant. Provavelmente em `tests/Integration/`.

### 3. Cuidados abertos em `rodarMigrationsNoSchema()`

Implementado copiando as migrations de negócio para `application/database/migrations_staging/`
e apontando `_migration_path` pra lá via `ReflectionProperty`. Pontos frágeis:

- `ReflectionProperty` em propriedade `protected` do CI3 funciona em PHP 8.4, mas
  quebra se o CI mudar de nome — vale um teste.
- O staging fica dentro de `APPPATH`. Em hospedagem que só dá escrita em
  `TMPPATH` isso falha; considerar `sys_get_temp_dir()`.
- O filtro é por substring do nome do arquivo (`create_tenants`). Se alguém criar
  outra migration de banco mestre, ela entra no tenant por engano. Uma convenção
  de nomenclatura ou uma lista explícita seria mais segura.

### 4. `excluirSchema()` para tenant em produção

Hoje só é chamado quando uma tentativa de provisionamento falha. A exclusão real
de um tenant (com backup e remoção do registro em `tenants`) é Fase 6.

### 5. Branch, commit e push

Nada foi commitado. Conventional Commits, ex.:

```
feat(multi-tenancy): fundação schema-per-tenant

Hook pre_system resolve o tenant pelo hostname e Tenant_manager provisiona
schema novo com as migrations do Map-OS. Sem tenant_id nas tabelas: o schema
é a fronteira. DB_MASTER_DATABASE vazio mantém a instalação única igual.

Co-Authored-By: Claude Code <noreply@anthropic.com>
```

Branch sugerida: `fase-1-multi-tenancy`.

## Ambiente: Cuidado

- **PHP da máquina é 8.4.27** (WinGet). O projeto exige `^8.5`.
- `composer install` foi rodado com `--ignore-platform-reqs`, e
  `application/vendor/` foi criado — está no `.gitignore`, não entra no commit.
- Com isso, **`composer test` roda, mas com 42 falhas e 6 erros pré-existentes**:
  são testes que leem código-fonte por regex e quebram por CRLF do Windows, mais
  um `proc_open(/dev/null)` que não existe no Windows. Nada a ver com a Fase 1.
  A baseline de erros é essa; qualquer falha nova precisa ser investigada.
- `composer analyse` (PHPStan): 3 erros pré-existentes, em `security_helper.php`
  e `Authorization_Token.php`, nenhum nos arquivos da Fase 1.
- `composer format` roda no repositório **inteiro** — em Windows regrava 400+
  arquivos por causa de fim de linha. Evite; formate só o que mudou.

## Verificação rápida para amanhã

```bash
php application/vendor/bin/phpstan analyse --memory-limit=1G
php application/vendor/bin/phpunit --filter TenantManagerTest
```
