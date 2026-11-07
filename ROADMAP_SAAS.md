# MAPOS — Mapa de Desenvolvimento SaaS (SLA + ITIL + ISO 20000/27001)

Versão base analisada: MAPOS v5.3.0 (ramonsilva20/mapos).
Contexto: Código em `C:\MALCHER\MALCHER\mapos` (clone do upstream; fork próprio ainda a ser conectado).

## Estratégia aprovada

**Monolito com limites de tenant**, não microservices nesta fase.
- Codebase PHP/CodeIgniter 3 compartilhado (single deploy).
- **Schema per tenant**: cada cliente SaaS recebe seu próprio schema MySQL dentro de um cluster; isolamento físico de dados (ISO 27001), backup/restauração por tenant.
- Banco mestre (tenant-registry): mapeia domínio → schema, plano, status, limites.
- Middleware resolve tenant pelo hostname / `X-Tenant` e injeta `tenant_id` + schema no contexto da requisição.
- Uploads em `STORAGE_PATH/{tenant_id}/` fora da raiz pública.

## Regras do projeto (AGENTS.md)

- Nunca editar `banco.sql` diretamente; usar migrations em `application/database/migrations/`.
- Commits no padrão Conventional Commits (`feat`, `fix`, `docs`, `refactor`, `chore`).
- Escapar output com `e()`; nunca SQL concatenado; Query Builder com bindings.
- Rodar `composer test` e `composer analyse` antes de qualquer PR.
- DESIGN.md é fonte da verdade para UI/tokens; não adicionar cores fixas.
- Segurança: `.env`, chaves e credenciais nunca no código; bcrypt para senhas; rate limiting em auth.

## Fases

| Fase | Entrega | Status | Prazo estimado |
|------|---------|--------|----------------|
| 1 | Multi-tenancy: tenant registry, provisionamento, `tenant_id` em tabelas, isolamento de consultas | em_progresso | 6–8 semanas |
| 2 | SLA: prioridade, severidade, níveis, prazos de resposta/resolução, brecha, dashboard | pendente | 4–6 semanas |
| 3 | ITIL: Incidentes, Problemas, Mudanças, CAB, base de conhecimento, CMDB leve | pendente | 6–8 semanas |
| 4 | Segurança ISO 27001 / LGPD: auditoria imutável, retenção, anonimização, exportação, criptografia | pendente | 4–6 semanas |
| 5 | Billing SaaS: planos, assinaturas recorrentes, faturas, cobrança via gateway, limites de plano | pendente | 4–6 semanas |
| 6 | Operação: worker de tarefas (SLA/notificações), monitoramento, backup por tenant, onboarding | pendente | 4–6 semanas |

Total estimado MVP SaaS: ~28–40 semanas.

## Tabelas novas (Fase 1)

- `tenants`: id, nome, cnpj, dominio, plano_id, status, data_expiracao, limites_json, criado_em, atualizado_em
- `tenant_usage`: tenant_id, metrica, valor, periodo

## Alterações (Fase 1)

**Sem coluna `tenant_id` nas tabelas de negócio.** O schema é a fronteira do tenant
(ADR `tenants/schema-per-tenant`): toda linha dentro de `mapos_<dominio>` pertence
àquele tenant, e o hook `application/hooks/tenant.php` já aponta a conexão para o
schema certo antes de qualquer modelo rodar. Uma coluna `tenant_id` seria um segundo
identificador da mesma verdade — capaz de divergir do schema — sem acrescentar
isolamento (o dado não pode vazar para outro schema, e um `WHERE tenant_id` errado
continuaria dentro do schema errado).

Consequência prática: os models de negócio **não são alterados** na Fase 1. O que
muda é só o que cerca a aplicação:
- `tenants` e `tenant_usage` no banco mestre (migrations novas).
- `application/hooks/tenant.php` (pre_system) + `Tenant_manager` (provisionamento).
- `DB_MASTER_DATABASE` em `.env`, documentado em `docs/multi-tenancy.md`.

O que cada tenant ganha de schema é exatamente o schema padrão do Map-OS — as 27
tabelas de negócio, com `ci_sessions` e `migrations` locais ao tenant. Não há
tabela "master" de configurações: `configuracoes` é do tenant, como as demais.

O `emitente` não recebe vínculo especial: a Fase 1 não cria registro por tenant,
ele já nasce naturalmente no schema de cada um.

SLA (`os`: `prioridade`, `severidade`, `sla_nivel_id`, `data_limite_resposta`,
`data_limite_resolucao`, `sla_breach`) é Fase 2, junto com as tabelas SLA, e não
entra aqui.

## Decisões pendentes do usuário

1. URL do fork no GitHub (para conectar o repositório e abrir branches/PRs).
2. Banco compartilhado com `tenant_id` vs. schema per tenant (recomendado: schema per tenant).
3. Escopo da Fase 1: só multi-tenancy, ou já incluir SLA?
4. Processo ITIL e controle ISO prioritários para o primeiro cliente.

## Critérios de aceitação da Fase 1

- Um request a `dominio.tenant.com` resolve o tenant e isolou seus dados.
- Nenhuma consulta global vaza dados entre tenants.
- Provisionamento de novo tenant cria schema e roda migrations automaticamente.
- Testes de isolamento por tenant passando (CI verde).
- Changelog atualizado.
