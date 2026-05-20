#!/usr/bin/env bash
set -euo pipefail

ENV_FILE=.env
if [ -f "$ENV_FILE" ]; then
  # load variables from .env (simple parser, does not handle complex quoting)
  export $(grep -v '^#' "$ENV_FILE" | sed 's/"//g' | sed "s/'//g" | xargs)
fi

DB_HOST=${DB_HOST:-localhost}
DB_NAME=${DB_NAME:-}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-}
DB_PORT=${DB_PORT:-}

if [ -z "$DB_NAME" ]; then
  echo "DB_NAME not set. Set DB_NAME in .env or export DB_NAME." >&2
  exit 2
fi

TIMESTAMP=$(date +%F_%H%M%S)
OUTFILE="backup_${DB_NAME}_${TIMESTAMP}.sql"
PORT_ARG=""
if [ -n "$DB_PORT" ]; then PORT_ARG="-P $DB_PORT"; fi

mysqldump -h "$DB_HOST" $PORT_ARG -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$OUTFILE"

echo "Backup written to $OUTFILE"
