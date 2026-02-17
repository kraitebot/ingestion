#!/bin/bash

# Setup parallel testing databases for Laravel
# This script creates separate databases for each parallel test process

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-password}"
DB_BASE_NAME="kraite_tests"
NUM_PROCESSES="${1:-20}"  # Default to 20 parallel processes

echo "Setting up $NUM_PROCESSES parallel test databases..."
echo "Base database: $DB_BASE_NAME"
echo ""

# Create base test database
echo "Creating base database: $DB_BASE_NAME"
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`$DB_BASE_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

# Create parallel test databases
for i in $(seq 1 $NUM_PROCESSES); do
    DB_NAME="${DB_BASE_NAME}_test_${i}"
    echo "Creating database: $DB_NAME"
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
done

echo ""
echo "✓ All parallel test databases created successfully!"
echo ""
echo "To drop all test databases, run:"
echo "  mysql -h$DB_HOST -P$DB_PORT -u$DB_USERNAME -p$DB_PASSWORD -e \"DROP DATABASE IF EXISTS \\\`$DB_BASE_NAME\\\`;\""
echo "  for i in {1..$NUM_PROCESSES}; do mysql -h$DB_HOST -P$DB_PORT -u$DB_USERNAME -p$DB_PASSWORD -e \"DROP DATABASE IF EXISTS \\\`${DB_BASE_NAME}_test_\$i\\\`;\"; done"
