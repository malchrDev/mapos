-- Roda uma vez, na criação do volume do MySQL.
-- mapos_master (banco mestre) já é criado por MYSQL_DATABASE. mapos_base é o
-- DB_DATABASE: a linha de comando (tools) abre a conexão nele; nenhuma empresa
-- usa esse banco.
CREATE DATABASE IF NOT EXISTS `mapos_base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- O usuário da aplicação cria e apaga os schemas das empresas (mapos_*), e só eles.
GRANT ALL PRIVILEGES ON `mapos\_%`.* TO 'mapos'@'%';
FLUSH PRIVILEGES;
