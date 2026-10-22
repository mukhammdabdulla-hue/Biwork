#!/bin/sh
# Creates the non-owner role the API and workers use. Row-level security applies to it;
# migrations run as the owner (POSTGRES_USER) and grant it access to every schema.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE ${APP_DB_USER} LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
GRANT CONNECT ON DATABASE ${POSTGRES_DB} TO ${APP_DB_USER};
SQL
