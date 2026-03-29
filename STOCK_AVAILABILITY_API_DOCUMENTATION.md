# Stock Availability API Documentation

## Overview
This API provides endpoints for managing stock availability records. It handles the stock-out screen functionality where users can add availability data through a popup interface.

## Base URL
```
/api/stock-availability
```

## Endpoints

### 1. Get Stock Availability Data for Popup
**GET** `/api/stock-availability/data`

Get stock availability data for a specific customer and date to populate the popup form.

**Parameters:**
- `customer_id` (required): Customer ID
- `date` (required): Date in YYYY-MM-DD format

**Response:**
```json
{
  "success": true,
  "message": "Stock availability data retrieved successfully",
  "data": {
    "customer_id": 1,
    "date": "2025-10-13",
    "products": [
      {
        "product_id": 1,
        "product_name": "Product A",
        "product_code": "PA001",
        "product_size": "Large",
        "product_unit": "Pieces",
        "opening_qty": 10,
        "stock_in_qty": 5,
        "stock_out_qty": 2,
        "calculated_available_qty": 13,
        "current_available_qty": 13,
        "closing_qty": 0,
        "notes": ""
      }
    ]
  }
}
```

### 2. Create Stock Availability Records
**POST** `/api/stock-availability`

Create multiple stock availability records for a customer and date.

**Request Body:**
```json
{
  "customer_id": 1,
  "date": "2025-10-13",
  "time": "14:30",
  "products": [
    {
      "product_id": 1,
      "closing_qty": 12,
      "notes": "End of day stock"
    },
    {
      "product_id": 2,
      "closing_qty": 8,
      "notes": "Partial consumption"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Stock availability records created successfully",
  "data": [
    {
      "id": 1,
      "customer_id": 1,
      "product_id": 1,
      "open_qty": 10,
      "stock_in_qty": 5,
      "stock_out_qty": 2,
      "calculated_available_qty": 13,
      "closing_qty": 12,
      "date": "2025-10-13",
      "time": "14:30:00",
      "notes": "End of day stock",
      "created_by": 1,
      "customer": {...},
      "product": {...},
      "creator": {...}
    }
  ]
}
```

### 3. Get Current Stock
**GET** `/api/stock-availability/current-stock`

Get current available stock for a specific customer and product.

**Parameters:**
- `customer_id` (required): Customer ID
- `product_id` (required): Product ID

**Response:**
```json
{
  "success": true,
  "message": "Current stock retrieved successfully",
  "data": {
    "customer_id": 1,
    "product_id": 1,
    "current_available_qty": 13
  }
}
```

### 4. List Stock Availability Records
**GET** `/api/stock-availability`

Get paginated list of stock availability records with filtering options.

**Query Parameters:**
- `search`: Search in customer name, company name, or product name/code
- `customer_id`: Filter by customer ID
- `product_id`: Filter by product ID
- `date_from`: Filter from date
- `date_to`: Filter to date
- `per_page`: Number of records per page (default: 15)

**Response:**
```json
{
  "success": true,
  "message": "Stock availability records retrieved successfully",
  "data": {
    "current_page": 1,
    "data": [...],
    "first_page_url": "...",
    "from": 1,
    "last_page": 5,
    "last_page_url": "...",
    "links": [...],
    "next_page_url": "...",
    "path": "...",
    "per_page": 15,
    "prev_page_url": null,
    "to": 15,
    "total": 75
  }
}
```

### 5. Get Single Stock Availability Record
**GET** `/api/stock-availability/{id}`

Get a specific stock availability record by ID.

**Response:**
```json
{
  "success": true,
  "message": "Stock availability record retrieved successfully",
  "data": {
    "id": 1,
    "customer_id": 1,
    "product_id": 1,
    "open_qty": 10,
    "stock_in_qty": 5,
    "stock_out_qty": 2,
    "calculated_available_qty": 13,
    "closing_qty": 12,
    "date": "2025-10-13",
    "time": "14:30:00",
    "notes": "End of day stock",
    "created_by": 1,
    "customer": {...},
    "product": {...},
    "creator": {...}
  }
}
```

### 6. Update Stock Availability Record
**PUT** `/api/stock-availability/{id}`

Update a specific stock availability record.

**Request Body:**
```json
{
  "closing_qty": 11,
  "notes": "Updated closing stock"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Stock availability record updated successfully",
  "data": {
    "id": 1,
    "customer_id": 1,
    "product_id": 1,
    "open_qty": 10,
    "stock_in_qty": 5,
    "stock_out_qty": 2,
    "calculated_available_qty": 13,
    "closing_qty": 11,
    "date": "2025-10-13",
    "time": "15:45:00",
    "notes": "Updated closing stock",
    "created_by": 1,
    "customer": {...},
    "product": {...},
    "creator": {...}
  }
}
```

### 7. Delete Stock Availability Record
**DELETE** `/api/stock-availability/{id}`

Delete a specific stock availability record.

**Response:**
```json
{
  "success": true,
  "message": "Stock availability record deleted successfully"
}
```

### 8. Recalculate Stock Availability
**POST** `/api/stock-availability/recalculate`

Recalculate stock availability for a specific date, customer, and/or product.

**Request Body:**
```json
{
  "date": "2025-10-13",
  "customer_id": 1,  // optional
  "product_id": 1    // optional
}
```

**Response:**
```json
{
  "success": true,
  "message": "Stock availability recalculated successfully"
}
```

### 9. Get Customers List
**GET** `/api/stock-availability/customers/list`

Get list of active customers for dropdown.

**Response:**
```json
{
  "success": true,
  "message": "Customers retrieved successfully",
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "company_name": "ABC Company"
    }
  ]
}
```

### 10. Get Products List
**GET** `/api/stock-availability/products/list`

Get list of active products for dropdown.

**Response:**
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 1,
      "name": "Product A",
      "code": "PA001",
      "size": "Large",
      "unit": "Pieces"
    }
  ]
}
```

## Stock Calculation Logic

### Opening Quantity
- Takes the `closing_qty` from the previous day's stock availability record
- If no previous day record exists, opening quantity is 0

### Stock In Quantity
- Customer deliveries: `stock_type = 'in'` AND `transfer_status != 'customer_transfer'`
- Transfers received: `stock_type = 'in'` AND `transfer_status = 'customer_transfer'` AND `customer_id = receiving_customer`

### Stock Out Quantity
- Transfers sent: `stock_type = 'out'` AND `transfer_status = 'customer_transfer'` AND `from_cust_id = sending_customer`

### Available Quantity
```
Available = Opening + Stock In - Stock Out
```

### Validation Rules
- Closing quantity cannot exceed calculated available quantity
- All quantities must be non-negative integers
- Customer and product must exist and be active

## Error Responses

### Validation Error (422)
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "customer_id": ["The customer id field is required."],
    "products.0.closing_qty": ["The closing qty must be at least 0."]
  }
}
```

### Business Logic Error (422)
```json
{
  "success": false,
  "message": "Closing quantity (15) cannot exceed calculated available quantity (13) for product ID 1"
}
```

### Server Error (500)
```json
{
  "success": false,
  "message": "Failed to create stock availability records",
  "error": "Database connection error"
}
```

## Usage Examples

### Frontend Integration

1. **Load Popup Data:**
```javascript
// Get availability data for popup
const response = await fetch('/api/stock-availability/data?customer_id=1&date=2025-10-13');
const data = await response.json();
```

2. **Save Multiple Products:**
```javascript
// Save stock availability for multiple products
const response = await fetch('/api/stock-availability', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + token
  },
  body: JSON.stringify({
    customer_id: 1,
    date: '2025-10-13',
    time: '14:30',
    products: [
      { product_id: 1, closing_qty: 12, notes: 'End of day' },
      { product_id: 2, closing_qty: 8, notes: 'Partial' }
    ]
  })
});
```

3. **Validate Before Save:**
```javascript
// Check current available stock before setting closing qty
const currentStock = await fetch('/api/stock-availability/current-stock?customer_id=1&product_id=1');
const stockData = await currentStock.json();
// Use stockData.data.current_available_qty for validation
```

## Database Tables

### stock_availability
- Stores individual product stock availability records
- One record per customer-product-date combination

### stock_availability_groups
- Groups stock availability records by customer and date
- Provides summary information for reports
- One record per customer-date combination
