-- Database and least-privilege accounts. TEMPLATE: replace the <...> placeholders when running it
-- (e.g. `sudo mariadb < create-users.sql` after editing a private copy). Never commit real passwords.
-- Generate each password with:  openssl rand -base64 33
--
-- Three accounts:
--   mcq_app      used by the running application (web, workers, scheduler, Reverb): data only, no DDL.
--   mcq_migrate  used only during deployments to run migrations: DDL + triggers.
--   mcq_backup   used by backups: read-only plus what mariadb-dump / mysqldump needs.

CREATE DATABASE IF NOT EXISTS mcq_competition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'mcq_app'@'localhost' IDENTIFIED BY '<APP_DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE
    ON mcq_competition.* TO 'mcq_app'@'localhost';

CREATE USER IF NOT EXISTS 'mcq_migrate'@'localhost' IDENTIFIED BY '<MIGRATE_DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE
    ON mcq_competition.* TO 'mcq_migrate'@'localhost';

CREATE USER IF NOT EXISTS 'mcq_backup'@'localhost' IDENTIFIED BY '<BACKUP_DB_PASSWORD>';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON mcq_competition.* TO 'mcq_backup'@'localhost';
GRANT PROCESS ON *.* TO 'mcq_backup'@'localhost';   -- lets the dump read tablespace metadata without warnings

FLUSH PRIVILEGES;

-- Append-only records are enforced by triggers created in migrations (audit_logs, ledger,
-- session_questions, prize_payouts, ...). With binary logging enabled (replication or
-- point-in-time recovery), creating triggers needs either SUPER or this server setting:
--   [mysqld]  log_bin_trust_function_creators = 1      (in /etc/mysql/mariadb.conf.d/60-mcq.cnf)
-- The triggers run with their DEFINER's rights (mcq_migrate), so mcq_migrate must not be dropped.
--
-- The application account has no DELETE/UPDATE exemptions: the triggers reject changes to
-- append-only rows for every account, including mcq_migrate.
