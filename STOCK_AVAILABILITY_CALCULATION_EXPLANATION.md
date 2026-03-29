# Stock Availability Calculation Explanation

## Where Stock Availability is Calculated

Stock availability is calculated in **two main places**:

### 1. `app/Services/StockAvailabilityService.php`
- **Method**: `calculateStockMovements($customerId, $productId, $date)`
- **Location**: Lines 65-127
- **Purpose**: Calculates stock in and stock out quantities for a specific customer, product, and date

### 2. `app/Models/StockAvailability.php`
- **Method**: `calculateStockMovements($customerId, $productId, $date)`
- **Location**: Lines 138-200
- **Purpose**: Same calculation logic (duplicate method in model)

## How Stock Availability is Calculated

### Formula:
```
Available Stock = Opening Stock + Stock In - Stock Out
```

Where:
- **Opening Stock** = Previous day's closing stock (from `stock_availability` table)
- **Stock In** = Deliveries accepted + Transfers received
- **Stock Out** = Transfers sent + Returns from deliveries

## Stock In Calculation (Lines 70-92 in StockAvailabilityService.php)

Stock In includes:

### 1. Deliveries Accepted (Lines 72-82)
```php
$deliveryInQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
    $query->where('customer_id', $customerId)
          ->whereDate('t_date', $dateObj)
          ->where(function($q) {
              $q->where('transfer_status', '!=', 'customer_transfer')
                ->orWhereNull('transfer_status'); // Include null transfer_status (deliveries)
          });
})
->where('product_id', $productId)
->where('stock_type', 'in')
->sum('stock_qty');
```

**Conditions:**
- `stock.customer_id` = customer ID
- `stock.t_date` = date (using `whereDate` for proper comparison)
- `stock.transfer_status != 'customer_transfer'` OR `transfer_status IS NULL`
- `stock_product.stock_type = 'in'`
- `stock_product.product_id` = product ID

### 2. Transfers Received (Lines 85-92)
```php
$transferInQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
    $query->where('customer_id', $customerId)
          ->whereDate('t_date', $dateObj)
          ->where('transfer_status', 'customer_transfer');
})
->where('product_id', $productId)
->where('stock_type', 'in')
->sum('stock_qty');
```

**Conditions:**
- `stock.customer_id` = customer ID (receiving customer)
- `stock.t_date` = date
- `stock.transfer_status = 'customer_transfer'`
- `stock_product.stock_type = 'in'`
- `stock_product.product_id` = product ID

## Stock Out Calculation (Lines 94-121)

Stock Out includes:

### 1. Transfers Sent (Lines 96-103)
```php
$transferOutQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
    $query->where('from_cust_id', $customerId) // Sending customer
          ->whereDate('t_date', $dateObj)
          ->where('transfer_status', 'customer_transfer');
})
->where('product_id', $productId)
->where('stock_type', 'out')
->sum('stock_qty');
```

### 2. Returns from Deliveries (Lines 106-119)
```php
$returnOutQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
    $query->where('customer_id', $customerId)
          ->whereDate('t_date', $dateObj)
          ->where(function($q) {
              $q->where('transfer_status', 'delivery_received')
                ->orWhere(function($subQ) {
                    $subQ->where('transfer_status', '!=', 'customer_transfer')
                         ->whereNotNull('delivery_id');
                });
          });
})
->where('product_id', $productId)
->where('stock_type', 'out')
->sum('stock_qty');
```

## How Stock Records are Created

### 1. Stock-In (Customer Stock-In Screen)

**Location**: `app/Http/Controllers/Api/StockController.php` - `createStockIn()` method

**Creates:**
- `Stock` record with:
  - `customer_id` = customer ID
  - `t_date` = stock-in date
  - `transfer_status` = NULL (or not set)
  - `from_cust_id` = NULL
  
- `StockProduct` record with:
  - `stock_id` = stock record ID
  - `product_id` = product ID
  - `stock_qty` = quantity
  - `stock_type` = 'in'

### 2. Delivery Acceptance

**Location**: `app/Http/Controllers/Api/DeliveryToCustomerController.php` - `acceptDelivery()` method

**Creates:**
- `Stock` record with:
  - `customer_id` = customer ID
  - `delivery_id` = delivery ID
  - `t_date` = delivery_date (accepted date)
  - `transfer_status` = 'delivery_received'
  
- `StockProduct` records:
  - For **accepted quantity** (received_qty = delivery_qty - return_qty):
    - `stock_type` = 'in'
    - `stock_qty` = received_qty
  - For **returned quantity** (if any):
    - `stock_type` = 'out'
    - `stock_qty` = return_qty

## Why Stock Might Not Be Showing

### Common Issues:

1. **Date Mismatch**
   - Stock record `t_date` doesn't match the date you're checking
   - Check: `stocks` table `t_date` column
   - Solution: Ensure `t_date` matches the date you're viewing

2. **Transfer Status Not Matching**
   - For deliveries: `transfer_status` must be `'delivery_received'` OR `NULL`
   - For stock-in: `transfer_status` should be `NULL` or not `'customer_transfer'`
   - Check: `stocks` table `transfer_status` column
   - Solution: Verify `transfer_status` is set correctly

3. **Stock Type Not Set**
   - `stock_product.stock_type` must be `'in'` for stock in
   - Check: `stocks_product` table `stock_type` column
   - Solution: Ensure `stock_type = 'in'` for stock-in records

4. **Stock Availability Not Recalculated**
   - Stock availability records are not automatically recalculated when stock is added
   - Check: `stock_availability` table for records with matching date
   - Solution: Stock availability is calculated on-demand when viewing/editing, or you need to manually trigger recalculation

5. **Soft Deleted Records**
   - Stock or StockProduct records might be soft deleted
   - Check: `stocks.deleted_at` and `stocks_product.deleted_at` columns
   - Solution: Ensure records are not soft deleted

## How to Debug

### 1. Check Stock Records
```sql
SELECT s.id, s.customer_id, s.t_date, s.transfer_status, s.delivery_id,
       sp.product_id, sp.stock_qty, sp.stock_type, sp.deleted_at
FROM stocks s
LEFT JOIN stocks_product sp ON s.id = sp.stock_id
WHERE s.customer_id = 5  -- Your customer ID
  AND DATE(s.t_date) = '2026-01-03'  -- Your date
  AND s.deleted_at IS NULL
  AND (sp.deleted_at IS NULL OR sp.deleted_at IS NULL);
```

### 2. Check Stock Availability Calculation
```sql
-- Check what the calculation would return
SELECT 
    customer_id,
    product_id,
    date,
    open_qty,
    stock_in_qty,
    stock_out_qty,
    calculated_available_qty,
    closing_qty
FROM stock_availability
WHERE customer_id = 5
  AND product_id = 3  -- Your product ID
  AND date = '2026-01-03';
```

### 3. Verify Stock In Calculation
The calculation looks for:
- `stocks.customer_id` = your customer ID
- `stocks.t_date` = your date (using `whereDate`)
- `stocks.transfer_status != 'customer_transfer'` OR `transfer_status IS NULL`
- `stocks_product.stock_type = 'in'`
- `stocks_product.product_id` = your product ID

## Manual Recalculation

If stock records exist but availability is not showing, you can manually recalculate:

### Using Tinker:
```php
php artisan tinker

use App\Services\StockAvailabilityService;

// Recalculate for specific customer, product, and date
StockAvailabilityService::calculateAndUpdateAvailability(5, 3, '2026-01-03');

// Recalculate for all dates for a customer-product combination
StockAvailabilityService::recalculateForCustomerProduct(5, 3);

// Recalculate for a specific date (all customers/products)
StockAvailabilityService::recalculateForDate('2026-01-03');
```

## Summary

Stock availability is calculated by:
1. Looking at `stocks` and `stocks_product` tables
2. Filtering by customer_id, product_id, and date
3. Summing `stock_qty` where `stock_type = 'in'` (stock in)
4. Summing `stock_qty` where `stock_type = 'out'` (stock out)
5. Adding opening stock (previous day's closing)
6. Calculating: `available = opening + in - out`

If stock is not showing, check:
- ✅ Stock records exist in `stocks` and `stocks_product` tables
- ✅ `t_date` matches the date you're checking
- ✅ `transfer_status` is correct (NULL or 'delivery_received' for deliveries)
- ✅ `stock_type = 'in'` for stock-in records
- ✅ Records are not soft deleted
- ✅ Stock availability record exists or needs to be recalculated

