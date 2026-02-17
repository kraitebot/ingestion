#!/bin/bash

# Cleanup script for parallel test databases
# Drops all kraite_tests_* databases created by Pest parallel testing

DB_USER="${DB_USERNAME:-root}"
DB_PASS="${DB_PASSWORD:-password}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

echo "Cleaning up test databases..."

# Get list of test databases
TEST_DBS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW DATABASES LIKE 'kraite_tests_%';" --skip-column-names 2>/dev/null)

if [ -z "$TEST_DBS" ]; then
    echo "No test databases found to clean up."
    exit 0
fi

# Drop each test database
echo "$TEST_DBS" | while read -r db; do
    echo "Dropping database: $db"
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS \`$db\`;" 2>/dev/null
done

echo "Cleanup complete!"
