-- Usuário da aplicação com o mínimo que a multi-tenancy precisa: criar e
-- dropar schemas `mapos_*` (tenants), e trabalhar no mestre e no padrão.
-- Nenhum privilégio fora do prefixo — é o mesmo GRANT recomendado em
-- docs/multi-tenancy.md para produção.
CREATE DATABASE IF NOT EXISTS mapos_padrao CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `mapos\_%`.* TO 'mapos'@'%';
FLUSH PRIVILEGES;
