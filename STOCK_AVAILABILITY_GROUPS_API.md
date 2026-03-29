# Stock Availability Groups API Documentation

## ✅ **Implementation Complete!**

I've successfully implemented the stock availability groups structure as requested. Here's the complete API documentation:

## **🏗️ Database Structure**

### **Tables:**
1. **`stock_availability_groups`** - Parent/grouping table
2. **`stock_availability`** - Child records with `stock_group_id` foreign key

### **Relationships:**
- `StockAvailabilityGroup` has many `StockAvailability` records
- `StockAvailability` belongs to `StockAvailabilityGroup`

## **📊 API Endpoints**

### **1. Stock Availability Groups (Main Report API)**

#### **GET `/api/stock-availability` - List Groups**
**Purpose**: Get paginated list of stock availability groups for the report

**Query Parameters:**
- `search` - Search by customer name/company
- `customer_id` - Filter by customer
- `date_from` - Filter from date
- `date_to` - Filter to date
- `per_page` - Items per page (default: 15)

**Response:**
```json
{
  "success": true,
  "message": "Stock availability groups retrieved successfully",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "customer_id": 3,
        "date": "2025-10-13",
        "time": "14:30:00",
        "notes": "Group notes",
        "created_by": 1,
        "created_at": "2025-10-13T14:30:00.000000Z",
        "updated_at": "2025-10-13T14:30:00.000000Z",
        "customer": {
          "id": 3,
          "name": "Customer Name",
          "company_name": "Company Name"
        },
        "creator": {
          "id": 1,
          "name": "Admin User"
        },
        "stock_availability_records": [
          {
            "id": 1,
            "stock_group_id": 1,
            "product_id": 2,
            "closing_qty": 500,
            "open_qty": 100,
            "stock_in_qty": 50,
            "stock_out_qty": 25,
            "calculated_available_qty": 125,
            "product": {
              "id": 2,
              "name": "Product Name",
              "code": "P001",
              "size": "Large"
            }
          }
        ]
      }
    ],
    "total": 10,
    "per_page": 15,
    "last_page": 1
  }
}
```

#### **GET `/api/stock-availability/{id}` - View Group Details**
**Purpose**: Get specific group with all its stock availability records

**Response:**
```json
{
  "success": true,
  "message": "Stock availability group retrieved successfully",
  "data": {
    "id": 1,
    "customer_id": 3,
    "date": "2025-10-13",
    "time": "14:30:00",
    "notes": "Group notes",
    "customer": {
      "id": 3,
      "name": "Customer Name",
      "company_name": "Company Name"
    },
    "creator": {
      "id": 1,
      "name": "Admin User"
    },
    "stock_availability_records": [
      {
        "id": 1,
        "stock_group_id": 1,
        "product_id": 2,
        "closing_qty": 500,
        "open_qty": 100,
        "stock_in_qty": 50,
        "stock_out_qty": 25,
        "calculated_available_qty": 125,
        "product": {
          "id": 2,
          "name": "Product Name",
          "code": "P001",
          "size": "Large"
        }
      }
    ]
  }
}
```

#### **POST `/api/stock-availability` - Create Group**
**Purpose**: Create new stock availability group with multiple products

**Request Body:**
```json
{
  "customer_id": 3,
  "date": "2025-10-13",
  "time": "14:30",
  "products": [
    {
      "product_id": 2,
      "closing_qty": 500,
      "notes": "Product notes"
    },
    {
      "product_id": 3,
      "closing_qty": 700,
      "notes": ""
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
      "stock_group_id": 1,
      "customer_id": 3,
      "product_id": 2,
      "closing_qty": 500,
      "open_qty": 100,
      "stock_in_qty": 50,
      "stock_out_qty": 25,
      "calculated_available_qty": 125,
      "customer": {...},
      "product": {...},
      "stockGroup": {...}
    }
  ]
}
```

#### **PUT `/api/stock-availability/{id}` - Update Group**
**Purpose**: Update existing group and its stock availability records

**Request Body:** (Same as create)

**Response:** (Same as create)

#### **DELETE `/api/stock-availability/{id}` - Delete Group**
**Purpose**: Delete group and all its stock availability records

**Response:**
```json
{
  "success": true,
  "message": "Stock availability group deleted successfully"
}
```

### **2. Legacy Individual Record APIs**

#### **GET `/api/stock-availability/records` - List Individual Records**
**Purpose**: Get individual stock availability records (legacy)

#### **GET `/api/stock-availability/records/{id}` - View Individual Record**
**Purpose**: Get specific individual record (legacy)

#### **PUT `/api/stock-availability/records/{id}` - Update Individual Record**
**Purpose**: Update specific individual record (legacy)

#### **DELETE `/api/stock-availability/records/{id}` - Delete Individual Record**
**Purpose**: Delete specific individual record (legacy)

### **3. Helper APIs**

#### **GET `/api/stock-availability/customers/list` - Get Customers**
**Purpose**: Get list of customers for dropdowns

#### **GET `/api/stock-availability/products/list` - Get Products**
**Purpose**: Get list of products for dropdowns

#### **GET `/api/stock-availability/data-simple` - Get Product Availability Data**
**Purpose**: Get product availability data for popup

#### **GET `/api/stock-availability/popup-products` - Get Products for Popup**
**Purpose**: Get specific product availability data

## **🎯 UI Implementation Guide**

### **Stock Availability Report Table Structure:**

| Column | Description | Data Source |
|--------|-------------|-------------|
| **Stock Group ID** | Unique group identifier | `stock_availability_groups.id` |
| **Date & Time** | Group date and time | `stock_availability_groups.date` + `stock_availability_groups.time` |
| **Customer** | Customer name/company | `stock_availability_groups.customer.name/company_name` |
| **Action** | View/Edit/Delete buttons | - |

### **View Modal Structure:**
When clicking "View", show:
- **Group Information**: Date, Time, Customer, Notes
- **Products Table**: 
  - Product Name/Code
  - Opening Qty
  - Stock In Qty
  - Stock Out Qty
  - Available Qty
  - Closing Qty

### **Edit Modal Structure:**
When clicking "Edit", show:
- **Group Form**: Customer, Date, Time, Notes
- **Products Form**: 
  - Product selection
  - Closing quantity input
  - Real-time availability calculation
  - Add/Remove products

## **🔄 Data Flow**

### **Create Flow:**
1. User fills form with customer, date, time
2. User adds products with closing quantities
3. API creates `stock_availability_group` record
4. API creates multiple `stock_availability` records with `stock_group_id`
5. All records are linked via foreign key

### **View Flow:**
1. API returns group with all related stock availability records
2. UI displays group info and products table
3. Shows calculated quantities for each product

### **Edit Flow:**
1. API returns group with all related records
2. UI populates form with existing data
3. User modifies data
4. API deletes old records and creates new ones
5. Maintains same group ID

### **Delete Flow:**
1. API deletes all related stock availability records
2. API deletes the group record
3. Cascade delete ensures data integrity

## **✅ Key Features Implemented:**

1. **Grouping Structure** - One group per customer per date
2. **Foreign Key Relationship** - `stock_availability.stock_group_id` → `stock_availability_groups.id`
3. **Complete CRUD Operations** - Create, Read, Update, Delete groups
4. **Data Integrity** - Cascade deletes and proper relationships
5. **Legacy Support** - Individual record APIs still available
6. **Validation** - Proper validation for all fields
7. **Error Handling** - Comprehensive error responses
8. **Pagination** - Efficient data loading
9. **Search & Filters** - Customer and date filtering
10. **Real-time Calculations** - Opening, in, out, available quantities

## **🚀 Ready for UI Integration:**

The API is now ready for frontend integration. The main endpoints to use are:

- **`GET /api/stock-availability`** - For the main report table
- **`GET /api/stock-availability/{id}`** - For view modal
- **`PUT /api/stock-availability/{id}`** - For edit modal
- **`DELETE /api/stock-availability/{id}`** - For delete action
- **`POST /api/stock-availability`** - For creating new groups

The structure now properly groups stock availability records by customer and date, making it perfect for the Stock Availability Report!
