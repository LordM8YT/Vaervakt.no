#!/usr/bin/env bash
# Simple deploy script using rsync. Adjust `REMOTE` before running or pass as first arg.
# Usage: ./deploy.sh user@host:/var/www/vaervakt.no
# Requires rsync/ssh access to target server.

set -euo pipefail

REMOTE=${1:-}
if [ -z "$REMOTE" ]; then
  echo "Usage: $0 user@host:/path/to/site"
  exit 2
fi

# Shared exclude file used by both local deploys and GitHub Actions.
EXCLUDE_FILE=".github/rsync-exclude.txt"

if [ ! -f "$EXCLUDE_FILE" ]; then
  echo "Missing $EXCLUDE_FILE"
  exit 3
fi

rsync -avz --delete --exclude-from="$EXCLUDE_FILE" ./ "$REMOTE"

echo "Deployed to $REMOTE"
