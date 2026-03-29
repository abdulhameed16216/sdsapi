# Stock Reports Implementation

## Overview
A comprehensive stock reporting system that automatically tracks and consolidates stock movements for each customer, product, and date combination.

## Database Structure

### Stock Reports Table (`stock_reports`)
- **Purpose**: One record per customer, product, and date combination
- **Key Fields**:
  - `customer_id`: Customer identifier
  - `product_id`: Product identifier  
  - `report_date`: Date of the report
  - `stock_in_qty`: Stock in + transfer received quantity
  - `transfer_received_qty`: Transfer received from other customers
  - `transfer_outside_qty`: Transfer sent to other customers
  - `current_availability_qty`: Current stock availability
  - `stock_used_qty`: Calculated stock used for the day
  - `notes`: Additional notes
  - `created_by`: User who created the record

### Unique Constraint
- Ensures only one record exists per `customer_id`, `product_id`, and `report_date` combination

## Automatic Updates via Database Triggers

The system uses database triggers to automatically update stock reports whenever changes occur in the related tables:

### Stock Table Triggers
- **INSERT**: Updates stock_in_qty and stock_used_qty when new stock entries are added
- **UPDATE**: Recalculates quantities when stock entries are modified
- **DELETE**: Recalculates quantities when stock entries are removed

### Stock Transfer Triggers
- **INSERT/UPDATE/DELETE**: Updates transfer_received_qty and transfer_outside_qty for both sending and receiving customers

### Stock Availability Triggers
- **INSERT/UPDATE/DELETE**: Updates current_availability_qty when stock availability changes

## API Endpoints

### Get Daily Stock Reports
```
GET /api/stock-reports/daily
```
**Parameters**:
- `date_from`: Start date (default: 30 days ago)
- `date_to`: End date (default: today)
- `customer_id`: Filter by customer (optional)
- `product_id`: Filter by product (optional)

### Get Stock Report Summary
```
GET /api/stock-reports/summary
```
**Parameters**: Same as daily reports
**Returns**: Aggregated totals across all reports

### Update Stock Report
```
POST /api/stock-reports/update
```
**Body**:
```json
{
  "customer_id": 1,
  "product_id": 1,
  "report_date": "2025-01-11",
  "stock_in_qty": 100,
  "transfer_received_qty": 50,
  "transfer_outside_qty": 25,
  "current_availability_qty": 125,
  "stock_used_qty": 75,
  "notes": "Optional notes"
}
```

### Recalculate Reports
```
POST /api/stock-reports/recalculate
```
**Parameters**:
- `date_from`: Start date for recalculation
- `date_to`: End date for recalculation

**Purpose**: Manually recalculate all stock reports for a date range (useful for data correction or initial setup)

## Model Features

### StockReport Model
- **Relationships**: Customer, Product, Creator
- **Scopes**: ByCustomer, ByProduct, ByDate, ByDateRange, Today
- **Accessors**: 
  - `total_stock_in`: Sum of stock_in_qty + transfer_received_qty
  - `net_stock_movement`: Total stock in - transfer outside
- **Static Method**: `updateOrCreateReport()` for easy report creation/updates

## Usage Examples

### Creating a Stock Report
```php
StockReport::updateOrCreateReport(1, 1, '2025-01-11', [
    'stock_in_qty' => 100,
    'transfer_received_qty' => 50,
    'transfer_outside_qty' => 25,
    'current_availability_qty' => 125,
    'stock_used_qty' => 75
]);
```

### Querying Reports
```php
// Get today's reports
$todayReports = StockReport::today()->get();

// Get reports for a specific customer
$customerReports = StockReport::byCustomer(1)->get();

// Get reports for a date range
$rangeReports = StockReport::byDateRange('2025-01-01', '2025-01-31')->get();
```

## Key Benefits

1. **Automatic Updates**: Database triggers ensure reports are always up-to-date
2. **Data Integrity**: Unique constraints prevent duplicate records
3. **Performance**: Indexed fields for fast queries
4. **Flexibility**: API endpoints support various filtering options
5. **Audit Trail**: Tracks who created/modified reports and when

## Data Flow

1. **Stock Entry**: When stock is added/removed → Trigger updates stock_in_qty/stock_used_qty
2. **Transfer**: When stock is transferred → Trigger updates transfer quantities for both customers
3. **Availability**: When stock availability changes → Trigger updates current_availability_qty
4. **Report Generation**: API endpoints provide filtered views of the consolidated data

## Maintenance

- **Recalculation**: Use the recalculate endpoint if data inconsistencies are detected
- **Cleanup**: Soft deletes are supported for audit purposes
- **Monitoring**: Check trigger performance if large volumes of data are processed

This implementation provides a robust, automated stock reporting system that maintains data consistency and provides comprehensive reporting capabilities.
