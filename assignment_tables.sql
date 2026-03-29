-- Assignment Management Tables
-- Run these SQL queries directly in your database

-- 1. Vendor-Machine Assignments Table
CREATE TABLE `vendor_machine_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` bigint(20) UNSIGNED NOT NULL,
  `machine_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vendor_machine_assignments_vendor_id_foreign` (`vendor_id`),
  KEY `vendor_machine_assignments_machine_id_foreign` (`machine_id`),
  KEY `vendor_machine_assignments_created_by_foreign` (`created_by`),
  KEY `vendor_machine_assignments_deleted_by_foreign` (`deleted_by`),
  KEY `vendor_machine_assignments_status_index` (`status`),
  KEY `vendor_machine_assignments_assigned_date_index` (`assigned_date`),
  CONSTRAINT `vendor_machine_assignments_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_machine_assignments_machine_id_foreign` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_machine_assignments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_machine_assignments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Employee-Vendor Assignments Table
CREATE TABLE `employee_vendor_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) UNSIGNED NOT NULL,
  `vendor_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_vendor_assignments_employee_id_foreign` (`employee_id`),
  KEY `employee_vendor_assignments_vendor_id_foreign` (`vendor_id`),
  KEY `employee_vendor_assignments_created_by_foreign` (`created_by`),
  KEY `employee_vendor_assignments_deleted_by_foreign` (`deleted_by`),
  KEY `employee_vendor_assignments_status_index` (`status`),
  KEY `employee_vendor_assignments_assigned_date_index` (`assigned_date`),
  CONSTRAINT `employee_vendor_assignments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_vendor_assignments_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_vendor_assignments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_vendor_assignments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add unique constraints to prevent duplicate assignments
-- (Optional - uncomment if you want to prevent duplicate assignments)

-- ALTER TABLE `vendor_machine_assignments` 
-- ADD UNIQUE KEY `unique_vendor_machine_active` (`vendor_id`, `machine_id`, `status`) 
-- WHERE `deleted_at` IS NULL AND `status` = 'active';

-- ALTER TABLE `employee_vendor_assignments` 
-- ADD UNIQUE KEY `unique_employee_vendor_active` (`employee_id`, `vendor_id`, `status`) 
-- WHERE `deleted_at` IS NULL AND `status` = 'active';

-- 4. Sample data (Optional - for testing)
-- INSERT INTO `vendor_machine_assignments` (`vendor_id`, `machine_id`, `assigned_date`, `notes`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
-- (1, 1, '2025-10-26', 'Initial assignment for testing', 'active', 1, NOW(), NOW()),
-- (2, 2, '2025-10-26', 'Coffee machine assignment', 'active', 1, NOW(), NOW());

-- INSERT INTO `employee_vendor_assignments` (`employee_id`, `vendor_id`, `assigned_date`, `notes`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
-- (1, 1, '2025-10-26', 'Primary contact for vendor', 'active', 1, NOW(), NOW()),
-- (2, 2, '2025-10-26', 'Backup contact for vendor', 'active', 1, NOW(), NOW());
