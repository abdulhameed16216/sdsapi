# EB Dashboard API Documentation

## Overview
This is a comprehensive Laravel API for managing vendors, operators, supervisors, stock, attendance, machines, and documents with role-based access control.

## Base URL
```
http://localhost:8000/api
```

## Authentication
All protected endpoints require a Bearer token in the Authorization header:
```
Authorization: Bearer {your-token}
```

## User Roles & Permissions

### Roles
1. **Super Admin** - Full system access
2. **Admin** - Administrative access (no role management)
3. **Supervisor** - Monitoring and reporting permissions
4. **Operator** - Basic operational permissions

### Permission Modules
- **Role Management** - Create/Read/Update/Delete roles
- **User Management** - Create/Read/Update/Delete users
- **Vendor Management** - Create/Read/Update/Delete vendors
- **Stock Management** - Create/Read/Update/Delete/Transfer stock
- **Product Management** - Create/Read/Update/Delete products
- **Attendance Management** - Create/Read/Update/Delete attendance
- **Machine Management** - Create/Read/Update/Delete machines, record readings
- **Document Management** - Create/Read/Update/Delete documents
- **Reports** - View and export reports
- **Dashboard** - View dashboard

## API Endpoints

### Authentication

#### Register User
```http
POST /api/register
```
**Body:**
```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "phone": "+1234567890"
}
```

#### Login
```http
POST /api/login
```
**Body:**
```json
{
    "email": "admin@ebdashboard.com",
    "password": "password"
}
```

#### Logout
```http
POST /api/logout
Authorization: Bearer {token}
```

#### Get Current User
```http
GET /api/user
Authorization: Bearer {token}
```

### User Management

#### List Users
```http
GET /api/users?search=john&role_id=1&status=active&per_page=15
Authorization: Bearer {token}
```

#### Create User
```http
POST /api/users
Authorization: Bearer {token}
```
**Body:**
```json
{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "password123",
    "phone": "+1234567891",
    "role_id": 2,
    "status": "active"
}
```

#### Get User
```http
GET /api/users/{id}
Authorization: Bearer {token}
```

#### Update User
```http
PUT /api/users/{id}
Authorization: Bearer {token}
```

#### Delete User
```http
DELETE /api/users/{id}
Authorization: Bearer {token}
```

#### User Statistics
```http
GET /api/users/stats
Authorization: Bearer {token}
```

### Role Management

#### List Roles
```http
GET /api/roles?search=admin&status=active
Authorization: Bearer {token}
```

#### Create Role
```http
POST /api/roles
Authorization: Bearer {token}
```
**Body:**
```json
{
    "name": "Manager",
    "slug": "manager",
    "description": "Manager role with specific permissions",
    "permissions": [1, 2, 3, 4]
}
```

#### Get Role
```http
GET /api/roles/{id}
Authorization: Bearer {token}
```

#### Update Role
```http
PUT /api/roles/{id}
Authorization: Bearer {token}
```

#### Delete Role
```http
DELETE /api/roles/{id}
Authorization: Bearer {token}
```

#### Get All Permissions
```http
GET /api/roles/{id}/permissions
Authorization: Bearer {token}
```

### Vendor Management

#### List Vendors
```http
GET /api/vendors?search=ABC&status=active&city=Mumbai
Authorization: Bearer {token}
```

#### Create Vendor
```http
POST /api/vendors
Authorization: Bearer {token}
```
**Body:**
```json
{
    "name": "ABC Construction Ltd",
    "code": "VENDOR001",
    "contact_person": "John Smith",
    "email": "john@abcconstruction.com",
    "phone": "+1234567890",
    "address": "123 Main Street",
    "city": "Mumbai",
    "state": "Maharashtra",
    "pincode": "400001",
    "gst_number": "27ABCDE1234F1Z5",
    "status": "active",
    "notes": "Primary construction vendor"
}
```

#### Get Vendor
```http
GET /api/vendors/{id}
Authorization: Bearer {token}
```

#### Update Vendor
```http
PUT /api/vendors/{id}
Authorization: Bearer {token}
```

#### Delete Vendor
```http
DELETE /api/vendors/{id}
Authorization: Bearer {token}
```

#### Vendor Statistics
```http
GET /api/vendors/{id}/stats
Authorization: Bearer {token}
```

### Product Management

#### List Products
```http
GET /api/products?search=diesel&status=active&unit=liters
Authorization: Bearer {token}
```

#### Create Product
```http
POST /api/products
Authorization: Bearer {token}
```
**Body:**
```json
{
    "name": "Diesel Fuel",
    "code": "PROD001",
    "description": "High-grade diesel fuel for generators",
    "unit": "liters",
    "price": 85.50,
    "status": "active"
}
```

#### Get Product
```http
GET /api/products/{id}
Authorization: Bearer {token}
```

#### Update Product
```http
PUT /api/products/{id}
Authorization: Bearer {token}
```

#### Delete Product
```http
DELETE /api/products/{id}
Authorization: Bearer {token}
```

#### Product Statistics
```http
GET /api/products/{id}/stats
Authorization: Bearer {token}
```

#### Available Units
```http
GET /api/products/units/list
Authorization: Bearer {token}
```

### Stock Management

#### List Stocks
```http
GET /api/stocks?vendor_id=1&product_id=1&status=low_stock&low_stock=true
Authorization: Bearer {token}
```

#### Create Stock
```http
POST /api/stocks
Authorization: Bearer {token}
```
**Body:**
```json
{
    "vendor_id": 1,
    "product_id": 1,
    "current_quantity": 1000.00,
    "minimum_threshold": 100.00,
    "maximum_capacity": 5000.00
}
```

#### Get Stock
```http
GET /api/stocks/{id}
Authorization: Bearer {token}
```

#### Update Stock
```http
PUT /api/stocks/{id}
Authorization: Bearer {token}
```

#### Record Stock Usage
```http
POST /api/stocks/usage
Authorization: Bearer {token}
```
**Body:**
```json
{
    "vendor_id": 1,
    "product_id": 1,
    "quantity": 50.00,
    "notes": "Used for generator maintenance"
}
```

#### Transfer Stock
```http
POST /api/stocks/transfer
Authorization: Bearer {token}
```
**Body:**
```json
{
    "from_vendor_id": 1,
    "to_vendor_id": 2,
    "product_id": 1,
    "quantity": 100.00,
    "notes": "Emergency transfer"
}
```

#### Stock Alerts
```http
GET /api/stocks/alerts
Authorization: Bearer {token}
```

#### Stock Transactions
```http
GET /api/stocks/transactions?vendor_id=1&product_id=1&type=usage&start_date=2024-01-01&end_date=2024-01-31
Authorization: Bearer {token}
```

### Attendance Management

#### List Attendances
```http
GET /api/attendances?user_id=1&vendor_id=1&start_date=2024-01-01&end_date=2024-01-31&status=present
Authorization: Bearer {token}
```

#### Check In (Create Attendance)
```http
POST /api/attendances
Authorization: Bearer {token}
```
**Body:**
```json
{
    "vendor_id": 1,
    "latitude": 19.0760,
    "longitude": 72.8777,
    "check_in_notes": "Arrived at site"
}
```

#### Check Out (Update Attendance)
```http
PUT /api/attendances/{id}
Authorization: Bearer {token}
```
**Body:**
```json
{
    "check_out_notes": "Work completed for the day"
}
```

#### Get Attendance
```http
GET /api/attendances/{id}
Authorization: Bearer {token}
```

#### Today's Attendance
```http
GET /api/attendances/today
Authorization: Bearer {token}
```

#### Attendance Summary
```http
GET /api/attendances/summary?user_id=1&start_date=2024-01-01&end_date=2024-01-31
Authorization: Bearer {token}
```

#### Available Vendors for Attendance
```http
GET /api/attendances/vendors/available
Authorization: Bearer {token}
```

### Machine Management

#### List Machines
```http
GET /api/machines?vendor_id=1&type=generator&status=active&needs_maintenance=true
Authorization: Bearer {token}
```

#### Create Machine
```http
POST /api/machines
Authorization: Bearer {token}
```
**Body:**
```json
{
    "vendor_id": 1,
    "name": "Generator G1",
    "model": "Cummins C150",
    "serial_number": "SN123456",
    "type": "generator",
    "specifications": "150 KVA, 3 Phase",
    "installation_date": "2024-01-01",
    "last_maintenance_date": "2024-01-15",
    "next_maintenance_date": "2024-02-15",
    "status": "active",
    "notes": "Primary generator"
}
```

#### Get Machine
```http
GET /api/machines/{id}
Authorization: Bearer {token}
```

#### Update Machine
```http
PUT /api/machines/{id}
Authorization: Bearer {token}
```

#### Record Machine Reading
```http
POST /api/machines/{id}/readings
Authorization: Bearer {token}
```
**Body:**
```json
{
    "reading_value": 1250.50,
    "reading_type": "hours",
    "unit": "hours",
    "reading_date": "2024-01-20",
    "notes": "Regular reading"
}
```

#### Get Machine Readings
```http
GET /api/machines/{id}/readings?start_date=2024-01-01&end_date=2024-01-31&reading_type=hours
Authorization: Bearer {token}
```

#### Machine Statistics
```http
GET /api/machines/{id}/stats
Authorization: Bearer {token}
```

#### Maintenance Alerts
```http
GET /api/machines/maintenance/alerts
Authorization: Bearer {token}
```

### Document Management

#### List Documents
```http
GET /api/documents?vendor_id=1&type=bill&status=active&expiring_soon=true
Authorization: Bearer {token}
```

#### Upload Document
```http
POST /api/documents
Authorization: Bearer {token}
Content-Type: multipart/form-data
```
**Body:**
```
vendor_id: 1
title: "Monthly Bill - January 2024"
type: "bill"
document_date: "2024-01-31"
amount: 15000.00
description: "Monthly electricity bill"
expiry_date: "2024-12-31"
file: [file upload]
```

#### Get Document
```http
GET /api/documents/{id}
Authorization: Bearer {token}
```

#### Update Document
```http
PUT /api/documents/{id}
Authorization: Bearer {token}
```

#### Delete Document
```http
DELETE /api/documents/{id}
Authorization: Bearer {token}
```

#### Download Document
```http
GET /api/documents/{id}/download
Authorization: Bearer {token}
```

#### Document Alerts
```http
GET /api/documents/alerts
Authorization: Bearer {token}
```

#### Document Types
```http
GET /api/documents/types/list
Authorization: Bearer {token}
```

### Dashboard & Reports

#### Dashboard Statistics
```http
GET /api/dashboard/stats
Authorization: Bearer {token}
```

#### Dashboard Analytics
```http
GET /api/dashboard/analytics?period=30
Authorization: Bearer {token}
```

#### Recent Activities
```http
GET /api/dashboard/recent-activities?limit=10
Authorization: Bearer {token}
```

### Health Check

#### API Health
```http
GET /api/health
```

## Response Format

All API responses follow this format:

### Success Response
```json
{
    "success": true,
    "message": "Operation completed successfully",
    "data": {
        // Response data
    }
}
```

### Error Response
```json
{
    "success": false,
    "message": "Error message",
    "errors": {
        // Validation errors (if any)
    }
}
```

### Paginated Response
```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            // Array of items
        ],
        "first_page_url": "http://localhost:8000/api/endpoint?page=1",
        "from": 1,
        "last_page": 10,
        "last_page_url": "http://localhost:8000/api/endpoint?page=10",
        "links": [
            // Pagination links
        ],
        "next_page_url": "http://localhost:8000/api/endpoint?page=2",
        "path": "http://localhost:8000/api/endpoint",
        "per_page": 15,
        "prev_page_url": null,
        "to": 15,
        "total": 150
    }
}
```

## Error Codes

- **200** - Success
- **201** - Created
- **400** - Bad Request
- **401** - Unauthorized
- **403** - Forbidden
- **404** - Not Found
- **422** - Validation Error
- **500** - Internal Server Error

## Default Users

After running the database seeder:

1. **Super Admin**
   - Email: `superadmin@ebdashboard.com`
   - Password: `password`

2. **Admin**
   - Email: `admin@ebdashboard.com`
   - Password: `password`

3. **Supervisor**
   - Email: `supervisor@ebdashboard.com`
   - Password: `password`

4. **Operator**
   - Email: `operator1@ebdashboard.com`
   - Password: `password`

## Mobile App Integration

### Key Features for Mobile Apps:

1. **Attendance Management**
   - GPS-based check-in/check-out
   - Real-time attendance tracking
   - Offline capability with sync

2. **Stock Management**
   - Record stock usage
   - Transfer stock between vendors
   - View stock levels and alerts

3. **Machine Readings**
   - Record daily machine readings
   - View machine status and maintenance alerts

4. **Document Management**
   - Upload vendor documents
   - View document alerts and expiry dates

### Mobile-Specific Endpoints:

- `POST /api/attendances` - Check-in with GPS coordinates
- `PUT /api/attendances/{id}` - Check-out
- `POST /api/stocks/usage` - Record stock usage
- `POST /api/stocks/transfer` - Transfer stock
- `POST /api/machines/{id}/readings` - Record machine readings
- `GET /api/attendances/today` - Today's attendance
- `GET /api/stocks/alerts` - Stock alerts
- `GET /api/machines/maintenance/alerts` - Maintenance alerts

## Setup Instructions

1. **Install Dependencies**
   ```bash
   composer install
   npm install
   ```

2. **Environment Setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Database Setup**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

4. **Start Development Server**
   ```bash
   php artisan serve
   ```

The API will be available at `http://localhost:8000/api`
