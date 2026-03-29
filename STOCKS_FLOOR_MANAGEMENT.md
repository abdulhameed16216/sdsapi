# Customer stock management (branch + floor)

This project stores customer stock in a **ledger style** using existing tables:

- `stocks` (header / transaction group)
- `stocks_product` (lines)
- `customers_floor` (floors/sections under a branch/location)

There is **no separate floor stock table**. Floor-level stock is represented by the column:

- `stocks_product.customer_floor_id`

## Core rule: what `customer_floor_id` means

For a given customer (branch/location) + product:

- **Branch pool stock** = all `stocks_product` lines where:
  - `stock.customer_id = <branch id>`
  - `stocks_product.customer_floor_id IS NULL`
  - then apply the formula: \(SUM(in) - (SUM(out) + SUM(sold-out))\)

- **Floor stock** (one floor) = all `stocks_product` lines where:
  - `stock.customer_id = <branch id>`
  - `stocks_product.customer_floor_id = <floor id>`
  - then apply the same formula

So the same product can exist in two scopes:

- **Location pool** (`customer_floor_id = NULL`)
- **Specific floor** (`customer_floor_id = <floor_id>`)

## Ledger line types (`stocks_product.stock_type`)

This codebase uses:

- `in` = stock added to a scope
- `out` = stock removed from a scope
- `sold-out` = daily usage / consumption (stock used)

Available stock is calculated from the ledger:

\[
available = SUM(in) - (SUM(out) + SUM(sold-out))
\]

## Transaction headers (`stocks.transfer_status`)

Common values used in current flows:

- `stock_in` = delivery stock-in to a customer (branch pool)
- `floor_allocation` = moving stock between **branch pool** and **floors** (or floor↔floor via two legs)
- `sold-out` = daily “stock used” entries

## How floor allocation is stored (branch ↔ floor)

When moving qty between branch pool and floor, we write a **balanced pair** of lines under one `stocks` header:

### Branch → Floor (allocate to floor)

- `out` line: `customer_floor_id = NULL`
- `in` line: `customer_floor_id = <floor_id>`

### Floor → Branch (return to pool)

- `out` line: `customer_floor_id = <floor_id>`
- `in` line: `customer_floor_id = NULL`

### Floor → Floor (same branch)

This is stored as **two legs** under one `stocks` header (`transfer_status = floor_allocation`):

1) Floor → Branch pool
2) Branch pool → Floor

That results in **4 lines** total (2 lines per leg), keeping the ledger consistent.

## How “Stock Used Today” is stored (sold-out)

Daily usage is stored as `stocks.transfer_status = sold-out` with line(s):

- `stocks_product.stock_type = sold-out`
- `stocks_product.stock_qty = used quantity`
- `stocks_product.customer_floor_id`:
  - `NULL` = branch-level used stock
  - `<floor_id>` = floor-level used stock

## Key screens / endpoints

### Split view (branch pool + floors)

- **GET** `GET /api/customers/{customer}/stock-by-location-floor`
  - returns totals for branch pool and each floor

### Simple move (branch ↔ floor)

- **POST** `POST /api/customers/{customer}/stock-by-location-floor/move`
  - body: `{ direction: 'to_floor'|'to_location', product_id, quantity, customer_floor_id }`
  - creates a `stocks` header with `transfer_status = floor_allocation` and the paired ledger lines

### Transfer-style list (DataTable) for floor allocations

- **GET** `GET /api/floor-stock-transfers?customer_id=<branchId>`
- **POST** `POST /api/floor-stock-transfers`
- **DELETE** `DELETE /api/floor-stock-transfers/{id}`

### Floor-based “stock used” report

- **GET** `GET /api/stock-availability/floor-used-today`
  - supports filters: `date`, `date_from`, `date_to`, `customer_id`, `customer_floor_id`
  - returns: group name, branch name, floor name (or NULL), used qty, date

## Important constraints / expectations

- Only **floor allocation** and **floor-based sold-out** should write `customer_floor_id`.
- Normal stock-in/stock-out/transfer between branches should typically remain **branch pool** (`customer_floor_id = NULL`) unless explicitly intended to be floor-scoped.
- “Deleting” a transaction uses soft-delete (`deleted_at`) so the ledger calculations must always filter `deleted_at IS NULL`.

