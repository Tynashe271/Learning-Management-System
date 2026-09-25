-- Least-privilege database role for the running application.
--
-- The application should never connect as the database owner or a superuser: if it were ever tricked into running attacker-chosen
-- SQL, that code would inherit every power the connection has. `lms_app` can read and write rows and nothing else: it cannot
-- create, alter, or drop tables, cannot truncate them, and has no admin rights. Schema changes (migrations) run through a separate
-- connection with the owner's credentials (see DB_MIGRATE_* in backend/.env.example and `migrate --database=pgsql_migrate`).
--
-- Safe to run repeatedly. Run it as the database owner:
--   psql -U lms -d lms -v app_password='choose-a-long-random-password' -f app-role.sql

SELECT format('CREATE ROLE lms_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_app') \gexec
SELECT format('ALTER ROLE lms_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD %L', :'app_password') \gexec

-- Nobody but the owner and this role may even connect, and nobody may create objects in the public schema.
-- (The maintenance database "postgres" is open to everyone by default; close it so this role cannot wander into it.)
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', datname) FROM pg_database WHERE datname = 'postgres' \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO lms_app', current_database()) \gexec
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO lms_app;

-- Data access on everything that exists now...
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO lms_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lms_app;
-- ...and on everything future migrations create.
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO lms_app', current_user) \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO lms_app', current_user) \gexec

-- A runaway query, a lock that never clears, or a transaction left open cannot hold the database hostage.
ALTER ROLE lms_app SET statement_timeout = '30s';
ALTER ROLE lms_app SET lock_timeout = '10s';
ALTER ROLE lms_app SET idle_in_transaction_session_timeout = '60s';
