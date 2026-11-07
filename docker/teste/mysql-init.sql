-- Bancos do ambiente local e dos testes de integração, e o usuário da aplicação
-- com o mínimo que a multi-tenancy precisa: criar e dropar schemas `mapos_*`.
-- Nenhum privilégio fora do prefixo — é o mesmo GRANT recomendado em
-- docs/multi-tenancy.md para produção.
--
-- mapos_master (MYSQL_DATABASE) e mapos_padrao: ambiente do navegador.
-- mapos_teste_master e mapos_teste_padrao: testes de integração, isolados.
CREATE DATABASE IF NOT EXISTS mapos_padrao CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS mapos_teste_master CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS mapos_teste_padrao CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `mapos\_%`.* TO 'mapos'@'%';
FLUSH PRIVILEGES;
