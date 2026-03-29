# Development Phase 2 – Customer Groups, Locations (Customers), Floors

## Overview

- Use the **existing `customers` table for location logic** (no table rename).
- Add **customer groups** and **customers_floor** tables.
- Add **customer_group_id** on **customers** and **location_id** (FK to `customers.id`) on **customers_floor**.

---

## 1. Tables & Schema

### 1.1 New table: `customer_groups`

| Column        | Type         | Nullable | Default | Notes                    |
|---------------|--------------|----------|---------|---------------------------|
| id            | bigint, PK   | No       | -       | Auto-increment            |
| name          | string(255)  | No       | -       | Customer group name       |
| description   | text         | Yes      | null    | Optional                  |
| status        | string(50)   | Yes      | active  | active / inactive         |
| created_by    | bigint, FK   | Yes      | null    | employees.id              |
| updated_by    | bigint, FK   | Yes      | null    | employees.id              |
| created_at    | timestamp    | -        | -       |                           |
| updated_at    | timestamp    | -        | -       |                           |
| deleted_at    | timestamp    | Yes      | null    | Soft deletes              |

- **Unique:** `name` (or name + where deleted_at is null, application-level if soft delete).
- **Indexes:** status, deleted_at.

---

### 1.2 Change: `customers` table (no table rename)

**New column:**

| Column            | Type        | Nullable | Default | Notes                          |
|-------------------|-------------|----------|---------|--------------------------------|
| customer_group_id | bigint, FK  | Yes      | null    | FK → customer_groups.id        |

- **Constraint:** `customers_customer_group_id_foreign` → `customer_groups(id)`, on delete: `set null` (or `restrict` if group must exist).
- **Index:** `customer_group_id` for list/filter by group.
- **Existing columns and table name:** unchanged.

---

### 1.3 New table: `customers_floor`

| Column      | Type         | Nullable | Default | Notes                              |
|-------------|--------------|----------|---------|------------------------------------|
| id          | bigint, PK   | No       | -       | Auto-increment                     |
| location_id | bigint, FK   | No       | -       | FK → **customers.id** (location)   |
| name        | string(255)  | No       | -       | Floor/section name                  |
| created_by  | bigint, FK   | Yes      | null    | employees.id                       |
| updated_by  | bigint, FK   | Yes      | null    | employees.id                       |
| created_at  | timestamp    | -        | -       |                                    |
| updated_at  | timestamp    | -        | -       |                                    |
| deleted_at  | timestamp    | Yes      | null    | Soft deletes                       |

- **FK:** `location_id` → `customers(id)`, on delete: `cascade` (floors go when location/customer is deleted).
- **Indexes:** location_id, deleted_at.
- **Clarification:** “Location” = one row in `customers`. So `customers_floor.location_id` references `customers.id` (the customer row that represents that location/branch).

---

## 2. Relationships (logical)

- **customer_groups** 1 —— N **customers** (customers.customer_group_id).
- **customers** (as location) 1 —— N **customers_floor** (customers_floor.location_id → customers.id).

So: **Group → many Customers (locations/branches) → each Customer (location) has many Floors.**

---

## 3. Implementation plan (order of work)

| Phase | Task | Deliverable |
|-------|------|-------------|
| 3.1   | Migration: create `customer_groups` table | New migration file |
| 3.2   | Migration: add `customer_group_id` to `customers` | New migration file |
| 3.3   | Migration: create `customers_floor` table with `location_id` FK to `customers.id` | New migration file |
| 3.4   | Eloquent model: `CustomerGroup` (fillable, casts, soft deletes, relations) | app/Models/CustomerGroup.php |
| 3.5   | Eloquent model: `CustomersFloor` or `CustomerFloor` (fillable, casts, soft deletes, location → Customer) | app/Models/CustomerFloor.php (or CustomersFloor per table name) |
| 3.6   | Update `Customer` model: add `customer_group_id` to fillable; relation `customerGroup()`; relation `floors()` for customers_floor | app/Models/Customer.php |
| 3.7   | (Optional) Seed or admin CRUD for customer_groups | - |
| 3.8   | API/UI: wire Customer Group UI (add/edit/view) to `customer_groups` and locations/floors to `customers` + `customers_floor` | Phase 2 API + frontend |

---

## 4. Naming / conventions

- **Table names (no change):** `customers` stays as is.
- **New tables:** `customer_groups`, `customers_floor` (as requested).
- **FK on customers_floor:** `location_id` → `customers.id` (location = customer row).

---

## 5. Open points / doubts

1. **customer_groups.name uniqueness:** Unique only among non-deleted rows (soft delete), or allow duplicate names after delete? Plan: application-level uniqueness when `deleted_at` is null; DB unique index optional.
2. **customers_floor:** Confirm that `location_id` is the only FK to `customers` (i.e. “location” = customer id). Plan assumes **yes**.
3. **On delete:** If a customer_group is deleted (soft/hard), should `customers.customer_group_id` be set to null (plan: `set null`) or prevent delete if customers exist? Plan: `set null` in migration.
4. **Model name for customers_floor:** Laravel often uses singular; table is `customers_floor`. Options: `CustomerFloor` (model) → table `customers_floor`, or `CustomersFloor`. Plan: `CustomerFloor` model, table `customers_floor`.

---

## 6. File checklist (after review)

- [ ] Migration: create `customer_groups`
- [ ] Migration: add `customer_group_id` to `customers`
- [ ] Migration: create `customers_floor` with `location_id` → `customers.id`
- [ ] Model: `CustomerGroup`
- [ ] Model: `CustomerFloor` (table `customers_floor`)
- [ ] Model: `Customer` updated (fillable, relations)
- [ ] Phase 2 API + UI wiring (separate task after migrations and models)

---

*Once this plan is reviewed and doubts clarified, implementation can start with migrations and models.*
