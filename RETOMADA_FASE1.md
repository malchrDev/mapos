# Retomada — Fase 1 (multi-tenancy), atualizado em 2026-11-07

## Onde paramos

Fundação de multi-tenancy **testada contra MySQL real** e **acessível no
navegador** (Docker em `docker/teste/`, ver `docs/multi-tenancy.md`, seção
"Ambiente local").

Commits na branch `fase-1-multi-tenancy`:

- `5e79eff8` — fundação (hook, Tenant_manager, tabelas do mestre).
- `ed3651db` — 4 erros críticos corrigidos (crase em `schema`, migrations no
  schema errado, mestre inconsistente, colisão de nome de schema).
- commit seguinte — falha fechada, `tenant_dominios`, admin inicial, base_url
  por hostname, ambiente web local.

## Verificação

```bash
docker compose -f docker/teste/docker-compose.yml up -d --build --wait
docker compose -f docker/teste/docker-compose.yml run --rm testes    # 23 testes de integração
php application/vendor/bin/phpstan analyse --memory-limit=1G         # 0 erros
php application/vendor/bin/phpunit --filter "TenantManagerTest|TenantDadosIniciaisTest"
```

A suíte unitária completa no Windows tem 14 falhas e 4 erros pré-existentes
(CRLF, `/dev/null`), nenhum em arquivo da Fase 1.

## Decisões tomadas (não rediscutir)

1. **Schema per tenant**, sem coluna `tenant_id` nas tabelas de negócio.
2. **Só multi-tenancy na Fase 1** — SLA é Fase 2.
3. `DB_MASTER_DATABASE` vazio desliga tudo: instalação única continua igual.
4. Tenant acessa por **subdomínio da plataforma e por domínio próprio** (ambos);
   domínio próprio só resolve com TXT de verificação.
5. **Painel administrativo da plataforma em subdomínio separado** (`SAAS_PAINEL_HOST`), no banco mestre, com contas próprias e auditoria.
6. Admin do tenant recebe acesso no **e-mail do pedido de inscrição**, depois da
   confirmação do pagamento — ou na hora, se o plano for **CORTESIA**. Até a
   Fase 5, a senha inicial aleatória sai no CLI.
7. Com multi-tenancy ligada, a web **falha fechada**: Host sem tenant → 404,
   mestre fora do ar → 503.

## O que falta na Fase 1 (em ordem)

1. **Uploads por tenant**: `STORAGE_PATH/<schema>/`.
2. **JWT da API vinculado ao tenant** (claim com o schema, recusado em outro Host).
3. Tarefas agendadas (cron do Map-OS, e-mails) rodando para cada tenant.
4. Painel da plataforma, próximos passos: 2FA para as contas do painel, telas
   para gerenciar as próprias contas (hoje só pelo CLI), cancelar/excluir
   empresa com backup (Fase 6), planos e cobrança (Fase 5).
5. PR da branch.

## Feito em 2026-11-07 (noite)

- Painel da plataforma em `SAAS_PAINEL_HOST` (ver docs/multi-tenancy.md).
- Provisionamento carrega o `banco.sql` como o instalador, em vez de rodar as
  migrations desde 2012: o banco de cada empresa sai idêntico ao de uma
  instalação nova. Antes, `cobrancas.charge_id` saía `int` (gateways quebrariam)
  e outras 10 colunas divergiam.
- `application/.env.example` voltou a ser lido pelo parser de INI (o comentário
  da Fase 1 tinha parênteses e quebrava o `CookieSeguroTest`).
