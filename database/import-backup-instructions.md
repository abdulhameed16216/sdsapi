# How to Import Live Database Backup

## Option 1: Using MySQL Command Line

1. If you have a SQL backup file:
   ```bash
   mysql -u root -p eb_dashboard < your_backup_file.sql
   ```

2. Or using MySQL in phpMyAdmin/XAMPP:
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Select database: `eb_dashboard`
   - Click "Import" tab
   - Choose your backup SQL file
   - Click "Go"

## Option 2: After Import, Mark Migrations as Run

After importing, you need to mark existing migrations as run:

```bash
php artisan migrate --pretend
```

Or manually insert migration records.

## Option 3: Generate Migrations from Existing Tables

After importing, you can generate migrations from your existing database structure:

```bash
composer require --dev kitloong/laravel-migrations-generator
php artisan migrate:generate
```

This will scan your database and create migration files for all existing tables.

