# Customer Stocks Logic Changes - Implementation Summary

## Overview
This document describes the comprehensive changes made to the customer stock management system, including the new customer returns workflow.

## 1. Stock Availability Table Changes

### New Column: `used_qty`
- **Purpose**: Stores the quantity of stock that was used/consumed by the customer
- **Type**: Integer, default 0
- **Logic**: Users now enter `used_qty` instead of `closing_qty`

### Calculation Logic
- **Available Quantity** (calculated dynamically):
  ```
  available_qty = opening_qty + stock_in_qty - stock_out_qty
  ```
  
- **Closing Quantity** (calculated from used_qty):
  ```
  closing_qty = available_qty - used_qty
  ```

### Columns Status
- `stock_in_qty` and `stock_out_qty`: Still stored but calculated from stock movements
- `calculated_available_qty`: Calculated and stored for reference
- `used_qty`: **NEW** - This is what users enter
- `closing_qty`: Calculated automatically from `available_qty - used_qty`

## 2. API Changes

### Stock Availability Endpoints

#### GET Endpoints
All GET endpoints now return:
- `stock_used_qty`: The quantity entered by user (from database)
- `calculated_available_qty`: Calculated available quantity
- `closing_qty`: Calculated closing quantity

#### POST/PUT Endpoints
All POST/PUT endpoints now accept:
- `stock_used_qty` (required): Instead of `closing_qty`
- Validation: `stock_used_qty <= calculated_available_qty`

**Example Request:**
```json
{
  "customer_id": 1,
  "date": "2025-12-25",
  "time": "14:30",
  "products": [
    {
      "product_id": 1,
      "stock_used_qty": 20,
      "notes": "Daily consumption"
    }
  ]
}
```

## 3. Customer Returns System

### New Table: `customer_returns`

#### Purpose
- Handles returns from customers (spoiled, damaged, expired products, etc.)
- **Important**: Returns do NOT affect customer stock until approved by admin
- After approval, returns can be moved to internal stocks or returned to vendor

#### Return Reasons
- `spoiled`: Product has spoiled
- `damaged`: Product is damaged
- `expired`: Product has expired
- `wrong_product`: Wrong product delivered
- `overstock`: Customer has overstock
- `customer_request`: Customer requested return
- `other`: Other reasons

#### Status Flow
1. **pending**: Return created, waiting for admin approval
2. **approved**: Admin approved, ready for action
3. **rejected**: Admin rejected the return
4. **moved_to_internal**: Moved to internal stocks
5. **returned_to_vendor**: Returned to vendor
6. **disposed**: Disposed/written off

### Customer Returns API Endpoints

#### Create Return
```
POST /api/customer-returns
```
- Creates return in `pending` status
- Does NOT affect customer stock
- Requires: customer_id, product_id, return_qty, return_reason, return_date

#### Approve Return (Admin Only)
```
POST /api/customer-returns/{id}/approve
```
- Changes status to `approved`
- Still does NOT affect stock
- Admin can add notes

#### Reject Return (Admin Only)
```
POST /api/customer-returns/{id}/reject
```
- Changes status to `rejected`
- Requires rejection_reason

#### Move to Internal Stocks (Admin Only)
```
POST /api/customer-returns/{id}/move-to-internal
```
- Only works if status is `approved`
- Creates internal stock record
- Updates return status to `moved_to_internal`
- **Now affects internal stocks**

#### Return to Vendor (Admin Only)
```
POST /api/customer-returns/{id}/return-to-vendor
```
- Only works if status is `approved`
- Updates return status to `returned_to_vendor`
- Records vendor_id

#### Dispose Return (Admin Only)
```
POST /api/customer-returns/{id}/dispose
```
- Only works if status is `approved`
- Updates return status to `disposed`
- For products that cannot be reused

### Return Workflow

```
Customer Return Created
        ↓
    [pending]
        ↓
    Admin Reviews
        ↓
    ┌───┴───┐
    │       │
[approved] [rejected]
    │
    └─── Admin Action
         │
    ┌────┼────┬────────┐
    │    │    │        │
[move_to_internal] [return_to_vendor] [disposed]
```

## 4. Key Business Rules

### Stock Availability
1. **Stock In**: Customer deliveries and transfers received
2. **Stock Out**: Transfers sent to other customers
3. **Stock Used**: Entered by user (consumption)
4. **Available Qty**: Calculated = Opening + In - Out
5. **Closing Qty**: Calculated = Available - Used

### Customer Returns
1. Returns are created in `pending` status
2. Returns do NOT affect customer stock until approved
3. Only admin/privileged users can approve returns
4. After approval, admin must take action:
   - Move to internal stocks (if reusable)
   - Return to vendor (if vendor accepts)
   - Dispose (if not usable)
5. Returns track reason, date, and all actions taken

## 5. Database Migrations

### Migration 1: Modify Stock Availability Table
- Adds `used_qty` column
- Migrates existing data: `used_qty = calculated_available_qty - closing_qty`

### Migration 2: Create Customer Returns Table
- Creates `customer_returns` table with all necessary fields
- Includes foreign keys and indexes

## 6. Models

### StockAvailability Model
- Added `used_qty` to fillable
- Updated casts

### CustomerReturn Model
- Complete model with relationships
- Helper methods for status checks
- Scopes for filtering

## 7. Controllers

### StockAvailabilityController
- Updated all methods to use `stock_used_qty`
- Calculate `closing_qty` from `used_qty`
- Return `available_qty` as calculated value

### CustomerReturnController
- Full CRUD operations
- Approval workflow methods
- Action methods (move to internal, return to vendor, dispose)

## 8. Routes

### Stock Availability Routes
- All existing routes remain the same
- API contract changed to use `stock_used_qty`

### Customer Returns Routes
```
GET    /api/customer-returns
POST   /api/customer-returns
GET    /api/customer-returns/{id}
PUT    /api/customer-returns/{id}
DELETE /api/customer-returns/{id}
POST   /api/customer-returns/{id}/approve
POST   /api/customer-returns/{id}/reject
POST   /api/customer-returns/{id}/move-to-internal
POST   /api/customer-returns/{id}/return-to-vendor
POST   /api/customer-returns/{id}/dispose
GET    /api/customer-returns/pending-count
GET    /api/customer-returns/customers/list
GET    /api/customer-returns/products/list
```

## 9. Benefits

1. **Better Logic**: Users enter what they used, not what's left
2. **Return Management**: Proper workflow for handling customer returns
3. **Stock Control**: Returns don't affect stock until approved
4. **Audit Trail**: Complete tracking of returns and actions
5. **Flexibility**: Multiple options for handling returns (internal, vendor, dispose)

## 10. Next Steps

1. Run migrations:
   ```bash
   php artisan migrate
   ```

2. Update frontend to:
   - Use `stock_used_qty` instead of `closing_qty`
   - Implement customer returns UI
   - Add admin approval interface

3. Test the workflow:
   - Create stock availability with used_qty
   - Create customer returns
   - Test approval workflow
   - Test moving to internal stocks

## 11. Important Notes

- **Backward Compatibility**: Existing `closing_qty` data is preserved
- **Migration**: Existing data is automatically migrated
- **Stock Calculations**: All calculations are done server-side
- **Returns**: Returns never affect customer stock until fully processed
- **Admin Only**: Approval and actions require admin privileges

