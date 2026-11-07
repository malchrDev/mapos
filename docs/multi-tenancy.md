# Multi-tenancy (Fase 1)

Cada cliente SaaS (tenant) tem o **seu próprio schema MySQL** dentro do mesmo
cluster. Não existe coluna `tenant_id` nas tabelas de negócio: o schema é a
fronteira, e toda linha dentro de `mapos_<dominio>` pertence àquele tenant.

## Como funciona

```
Requisição → hostname (HTTP_HOST)
  → hook pre_system (application/hooks/tenant.php)
    → SELECT em tenants no banco MESTRE
      → $_ENV['DB_DATABASE'] = schema do tenant
        → CodeIgniter carrega config/database.php já no schema certo
```

O hook roda **antes** de o framework abrir a conexão. Nenhum model, controller ou
view sabe que multi-tenancy existe: a conexão já nasce apontando para o schema do
tenant.

## Configuração (`application/.env`)

| Variável | O que é |
| --- | --- |
| `DB_MASTER_DATABASE` | Banco onde vivem `tenants` e `tenant_usage`. **Precisa ser um banco diferente dos schemas dos tenants.** |
| `DB_DATABASE` | Valor de fallback, e banco de trabalho em instalação única. |

`DB_MASTER_DATABASE` vazio desliga o hook: o sistema funciona como o Map-OS de
sempre, em instalação única. É o padrão, e assim continua.

### Exemplo

```env
DB_HOSTNAME=localhost
DB_USERNAME=mapos
DB_PASSWORD=...
DB_DATABASE=mapos_padrao
DB_MASTER_DATABASE=mapos_master
```

### Usuário do MySQL

O usuário da aplicação precisa criar e dropar os schemas dos tenants. Basta o
prefixo, nada além:

```sql
GRANT ALL PRIVILEGES ON `mapos\_%`.* TO 'mapos'@'%';
```

## Comandos (CLI)

```bash
php index.php tools tenant_mestre                    # cria/atualiza tenants e tenant_usage no mestre
php index.php tools tenant_criar loja.exemplo.com "Loja%20Exemplo"
php index.php tools tenants_migrate                  # leva todos os tenants à última migration
```

Depois de cada deploy: `tenant_mestre` e `tenants_migrate`. Enquanto o painel
administrativo (subdomínio próprio) não existe, o provisionamento é por aqui.

## Provisionamento

`Tenant_manager::provisionar()` (application/libraries/Tenant_manager.php), nesta
ordem:

1. Recusa domínio inválido e domínio já registrado **em qualquer status**
   (suspenso e cancelado continuam reservados).
2. **`gerarNomeSchema()`** — `mapos_<dominio sem pontuação>_<6 hex aleatórios>`.
   O sufixo impede que `a-b.com` e `ab.com` caiam no mesmo schema.
3. **`criarSchema()`** — `CREATE DATABASE`. Schema que já existe é **erro**, nunca
   reaproveitado: reaproveitar entregaria a um tenant os dados de outro.
4. **`migrarSchema()`** — roda as migrations de negócio numa conexão própria do
   schema novo. `$CI->db`/`$CI->dbforge` são trocados durante a execução e
   devolvidos no fim.
5. **INSERT em `tenants`** no mestre — só depois das tabelas existirem.

A ordem é do mais barato de desfazer para o mais caro. Se as migrations ou o
INSERT falharem, o schema é dropado: sem registro em `tenants` nenhuma
requisição o resolve, então ele não vaza acesso.

O `Tenant_manager` abre as próprias conexões (uma com o mestre, uma por schema),
com as credenciais da conexão da aplicação e `db_debug` desligado — os erros
viram exceção, para que o provisionamento consiga desfazer o que já fez.

### Migrations do mestre ficam em outra pasta

`application/database/migrations_mestre/` (`create_tenants`, `create_tenant_usage`)
só roda no `DB_MASTER_DATABASE`. `application/database/migrations/` são as de
negócio: rodam em cada tenant e na instalação única. Separadas, nenhuma
migration do mestre entra num tenant, e a verificação de "banco desatualizado"
do painel (que compara a pasta com a versão do banco) continua correta em cada
tenant.

## Testes de integração (MySQL real)

```bash
docker compose -f docker/teste/docker-compose.yml up -d --build --wait mysql
docker compose -f docker/teste/docker-compose.yml run --rm php
docker compose -f docker/teste/docker-compose.yml down -v
```

`tests/Integration/TenantIsolamentoTest.php` provisiona tenants de verdade e
confere que cada Host só enxerga o próprio schema, que domínio repetido e schema
existente são recusados, que tenant suspenso não resolve e que o banco padrão
não é tocado. Fora do container (sem `DB_MASTER_DATABASE`) os testes são pulados.

## Resolução e status

Só tenant com `status = 'ativo'` resolve. `suspenso` (inadimplência, bloqueio) e
`cancelado` continuam sem acesso, mesmo que o schema ainda exista — o acesso é
barrado no hook, não pela existência do banco.

O hostname é validado com `FILTER_VALIDATE_DOMAIN` antes de qualquer consulta, e a
consulta ao mestre usa statement parametrizado (PDO).

## Banco mestre fora do ar

O hook registra a falha no log do PHP (`error_log`) e segue com o `DB_DATABASE`
configurado. Uma falha do
banco mestre não pode derrubar a aplicação inteira antes de o framework nascer; o
erro aparece na tela normal de falha de banco.

## Exclusão de tenant (LGPD)

`excluirSchema()` faz `DROP DATABASE` do schema inteiro — o direito ao
esquecimento da empresa é uma operação, não uma varredura linha por linha.
`provisionar()` só a chama em uma tentativa que falhou; a exclusão definitiva de um
tenant em produção é da Fase 6 (backup por tenant), junto com a remoção do
registro em `tenants`.

O nome do schema entra em SQL cru (`CREATE`/`DROP DATABASE` não aceita binding),
então `validarNomeSchema()` só aceita `mapos_` + `[a-z0-9_]{1,50}`. Nada de
backtick, espaço, ponto ou hífen.

## Limites de plano

`tenant_usage` guarda o consumo por tenant, métrica e período (`AAAA-MM`):
`usuarios`, `os_mes`, `armazenamento_mb`. Fica no banco mestre porque o plano
cobra o consumo, e o consumo precisa ser lido **mesmo com o tenant suspenso**.

O limite em si é aplicado na Fase 5 (billing). Na Fase 1 a tabela e o registro
existem; nada ainda os consulta.

## Instalação única continua igual

Sem `DB_MASTER_DATABASE`, sem banco mestre, sem schema por cliente: o hook não faz nada
e o banco é o `DB_DATABASE` de sempre. Nenhuma instalação existente do Map-OS
precisa mudar.
