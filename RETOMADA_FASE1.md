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

1. ~~Uploads por tenant~~ feito em 2026-11-07 (`STORAGE_PATH/<schema>/`).
2. ~~JWT da API vinculado ao tenant~~ feito em 2026-11-07 (chave derivada por schema + claim `ten`).
3. ~~Tarefas agendadas por tenant~~ feito em 2026-11-07: `tools tenants_tarefa`
   (fila de e-mails), hook na CLI por `MAPOS_TENANT_SCHEMA`,
   `SAAS_EMAIL_REMETENTE`. Pendente: os logs (`application/logs`) ainda
   misturam as empresas, sem indicar de qual é cada linha.
4. ~~Painel da plataforma~~ feito em 2026-11-07: 2FA obrigatório, contas do
   painel, minha conta, planos e vencimento, backup, cancelar e excluir
   empresa. Fica para a Fase 5: cobrança (gateway a escolher) e bloqueio
   automático por vencimento e por limite do plano.
5. PR da branch.

## Backup: o que falta (combinado em 2026-11-07, fazer no fim da lista)

Hoje o painel gera o backup de uma empresa na hora (.zip com banco, uploads,
logo, fotos e LEIA-ME) e guarda em `SAAS_BACKUP_PATH`, no próprio servidor.

1. **Backup automático da plataforma** (o oficial, sob controle da plataforma):
   diário pelo cron do item 3, enviado para armazenamento de objetos fora do
   servidor, criptografado antes de sair, com retenção (por exemplo, 30
   diários e 12 mensais). **Decisão pendente:** qual serviço (sugestão:
   Backblaze B2 ou Cloudflare R2, compatíveis com S3; alternativas: AWS S3,
   Wasabi, Google Cloud Storage).
2. **Restaurar pelo painel** a partir de um backup (hoje é manual, pelo LEIA-ME).
3. **Opcional: exportação para o Google Drive do próprio cliente**, configurada
   pelo admin da empresa. É uma cópia do cliente e não substitui o backup
   oficial. Cuidados:
   - escopo `drive.file` (o escopo do Drive inteiro exige auditoria paga do Google);
   - exportação sem segredos (hashes de senha, segredos de 2FA, tokens);
   - token do Google cifrado no banco da empresa;
   - pode ser vantagem de plano.

## Feito em 2026-11-07 (noite)

- Painel da plataforma em `SAAS_PAINEL_HOST` (ver docs/multi-tenancy.md).
- Provisionamento carrega o `banco.sql` como o instalador, em vez de rodar as
  migrations desde 2012: o banco de cada empresa sai idêntico ao de uma
  instalação nova. Antes, `cobrancas.charge_id` saía `int` (gateways quebrariam)
  e outras 10 colunas divergiam.
- `application/.env.example` voltou a ser lido pelo parser de INI (o comentário
  da Fase 1 tinha parênteses e quebrava o `CookieSeguroTest`).
