# Steps After Importing Live Database Backup

## Step 1: Import Your SQL Backup
Import your live database SQL backup into the `eb_dashboard` database using phpMyAdmin or MySQL.

## Step 2: Check Existing Tables
Run this in phpMyAdmin or MySQL:
```sql
SHOW TABLES;
```

## Step 3: Mark Migrations as Run (Option A - Manual)
After import, manually insert records into the `migrations` table for all existing tables.

## Step 4: Add New Migration for Employee Assignments
The `employee_customer_machine_assignments` table needs to be created:
```bash
php artisan migrate --path=database/migrations/2025_01_01_000000_create_employee_customer_machine_assignments_table.php
```

## Alternative: Generate Migrations from Existing Database
After importing, you can generate migrations from your existing structure:
1. Install the generator:
   ```bash
   composer require --dev kitloong/laravel-migrations-generator
   ```
2. Generate migrations:
   ```bash
   php artisan migrate:generate
   ```
This will create migration files for all existing tables.

