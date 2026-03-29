-- After importing your live database backup, run this SQL to mark all existing migrations as run
-- This prevents Laravel from trying to create tables that already exist

-- First, let's see which migrations should be marked as run
-- Replace 'YOUR_BATCH_NUMBER' with a batch number (start with 1)

-- Example: Mark common migrations as run
-- Adjust the migration names and batch numbers based on your live database structure

-- You can run this query to insert migration records manually:
-- INSERT INTO `migrations` (`migration`, `batch`) VALUES
-- ('2019_12_14_000001_create_personal_access_tokens_table', 1),
-- ('2025_10_05_141414_create_roles_table', 1),
-- ('2025_10_05_141439_create_employees_table', 1),
-- ('2025_10_05_141600_create_users_table', 1),
-- ('2025_10_09_145925_create_customers_table', 1),
-- ('2025_10_09_161017_create_machines_table', 1),
-- ('2025_10_09_164638_create_products_table', 1),
-- ... (add all your existing migrations)
-- ON DUPLICATE KEY UPDATE batch = VALUES(batch);

-- Or better: After importing, check what tables exist and create a script to mark those migrations

