#!/bin/bash
# Script to backup old migrations and generate new ones from database

echo "Backing up old migrations..."
mkdir -p database/migrations_backup
cp database/migrations/*.php database/migrations_backup/ 2>/dev/null

echo "Clearing old migrations (keeping backup)..."
# Note: Keep the migrations directory, just clear the files
# We'll generate new ones

echo "Generating migrations from database..."
php artisan migrate:generate --path=database/migrations

echo "Done!"

