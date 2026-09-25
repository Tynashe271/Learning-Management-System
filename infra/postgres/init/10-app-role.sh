#!/bin/sh
# Runs once, when the database is first created (the postgres image executes everything in /docker-entrypoint-initdb.d).
# Creates the least-privilege role the application connects as; see app-role.sql for what it may and may not do.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v app_password="${LMS_APP_DB_PASSWORD:?set LMS_APP_DB_PASSWORD}" -f /opt/lms/app-role.sql
