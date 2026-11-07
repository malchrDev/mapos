# Retomada — Fase 1 (multi-tenancy), atualizado em 2026-11-07

## Onde paramos

Fundação de multi-tenancy **testada contra MySQL real** (Docker em
`docker/teste/`). O commit `5e79eff8` (2026-11-06) tinha quatro erros críticos,
corrigidos em 2026-11-07 (ainda não commitados):

1. `schema` é palavra reservada no MySQL: a consulta do hook não compilava, o
   erro era engolido e todo tenant caía no banco padrão. Agora com crase.
2. `load->database($config, true)` não troca `$CI->db`: as migrations rodavam no
   banco padrão (e rebaixavam a versão dele). Agora o `Tenant_manager` abre a
   conexão do schema e troca `$CI->db`/`$CI->dbforge` durante as migrations.
3. Hook lia `tenants` no `DB_MASTER_DATABASE`, `Tenant_manager` gravava no
   `DB_DATABASE`. Agora tudo do mestre passa por `Tenant_manager::mestre()`, e as
   migrations do mestre estão em `application/database/migrations_mestre/`.
4. `a-b.com` e `ab.com` geravam o mesmo schema e `criarSchema()` reaproveitava
   schema existente. Agora o nome tem sufixo aleatório e schema existente é erro.

Também: `DB_MASTER_DATABASE` ausente não cai mais para `DB_DATABASE`; domínio
suspenso/cancelado continua reservado; falha do mestre vai para o `error_log`;
CLI `tools tenant_mestre`, `tenant_criar`, `tenants_migrate`.

## Verificação

```bash
docker compose -f docker/teste/docker-compose.yml up -d --build --wait mysql
docker compose -f docker/teste/docker-compose.yml run --rm php     # 10 testes de integração
php application/vendor/bin/phpstan analyse --memory-limit=1G        # 0 erros
php application/vendor/bin/phpunit --filter TenantManagerTest        # 21 testes
```

A suíte unitária completa no Windows tem 14 falhas e 4 erros pré-existentes
(CRLF, `/dev/null`), nenhum em arquivo da Fase 1.

## Decisões tomadas (não rediscutir)

1. **Schema per tenant**, sem coluna `tenant_id` nas tabelas de negócio.
2. **Só multi-tenancy na Fase 1** — SLA é Fase 2.
3. `DB_MASTER_DATABASE` vazio desliga tudo: instalação única continua igual.
4. Tenant acessa por **subdomínio da plataforma e por domínio próprio** (ambos).
5. **Painel administrativo da plataforma em subdomínio separado.**
6. Admin do tenant recebe acesso no **e-mail do pedido de inscrição**, depois da
   confirmação do pagamento — ou na hora, se o plano for **CORTESIA**.

## O que falta na Fase 1 (em ordem)

1. **Falhar fechado**: com multi-tenancy ligada, Host sem tenant (ou mestre fora
   do ar) hoje cai no `DB_DATABASE`. Deve responder 404/503 — exceto o host do
   painel administrativo.
2. **Vários domínios por tenant** (`tenant_dominios`, 1:N, com verificação DNS)
   para suportar subdomínio + domínio próprio. Fazer antes de existir dado real.
3. **Dados iniciais do tenant**: permissões padrão, usuário admin (e-mail do
   pedido, senha definida por link com token), `configuracoes`, `emitente`.
4. **Uploads por tenant**: `STORAGE_PATH/<schema>/`.
5. **JWT da API vinculado ao tenant** (claim com o schema/id, recusado em outro Host).
6. `base_url`/`cookie_domain` derivados do Host resolvido.
7. Branch, commit e PR.
