#!/bin/bash

# Cleanup script for parallel test databases
# Drops all kraite_tests_* databases created by Pest parallel testing

set -euo pipefail

DB_USER="${DB_USERNAME:-root}"
DB_PASS="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

MYSQL_ARGS=(-h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER")

if [ -n "$DB_PASS" ]; then
    MYSQL_ARGS+=("-p$DB_PASS")
fi

echo "Cleaning up test databases..."

# Get list of test databases
TEST_DBS=$(mysql "${MYSQL_ARGS[@]}" --batch --skip-column-names -e "SHOW DATABASES LIKE 'kraite\\_tests\\_%';")

if [ -z "$TEST_DBS" ]; then
    echo "No test databases found to clean up."
    exit 0
fi

# Drop each test database
while IFS= read -r db; do
    echo "Dropping database: $db"
    mysql "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$db\`;"
done <<< "$TEST_DBS"

echo "Cleanup complete!"
