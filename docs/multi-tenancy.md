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

## Provisionamento

`Tenant_manager::provisionar()` (application/libraries/Tenant_manager.php), nesta
ordem:

1. **`criarSchema()`** — `CREATE DATABASE mapos_<dominio>`.
2. **`rodarMigrationsNoSchema()`** — copia as migrations de negócio para um
   diretório de staging (sem as duas do banco mestre) e roda `migration->latest()`
   com a conexão apontada para o schema novo. Ao final, volta a conexão ao mestre.
3. **INSERT em `tenants`** — só depois das tabelas existirem.

A ordem é do mais barato de desfazer para o mais caro. Se as migrations falharem,
o schema é dropado: sem registro em `tenants` nenhuma requisição o resolve, então
ele não vaza acesso — só ocupa espaço no cluster.

### O que não roda no schema do tenant

`20261106000000_create_tenants.php` e `20261106000100_create_tenant_usage.php`:
criam tabelas do banco mestre. Rodá-las dentro de um tenant criaria uma tabela
`tenants` em cada tenant.

## Resolução e status

Só tenant com `status = 'ativo'` resolve. `suspenso` (inadimplência, bloqueio) e
`cancelado` continuam sem acesso, mesmo que o schema ainda exista — o acesso é
barrado no hook, não pela existência do banco.

O hostname é validado com `FILTER_VALIDATE_DOMAIN` antes de qualquer consulta, e a
consulta ao mestre usa statement parametrizado (PDO).

## Banco mestre fora do ar

O hook engole a exceção e segue com o `DB_DATABASE` configurado. Uma falha do
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

Sem `DB_MASTER_DATABASE`, sem staging, sem schema por cliente: o hook não faz nada
e o banco é o `DB_DATABASE` de sempre. Nenhuma instalação existente do Map-OS
precisa mudar.
