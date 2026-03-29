# Location-Based Punch In/Out API Documentation

## Endpoint
`POST /api/attendance/location-punch`

## Authentication
Requires Bearer token authentication (JWT)

---

## 1. Punch In Request

### Request Payload

```json
{
  "action": "punch_in",
  "latitude": 12.9715987,
  "longitude": 77.5945627,
  "type": "location"
}
```

### Minimal Required Payload

```json
{
  "action": "punch_in",
  "latitude": 12.9715987,
  "longitude": 77.5945627,
  "type": "location"
}
```

### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | Must be `"punch_in"` or `"punch_out"` |
| `latitude` | decimal | Yes | Latitude coordinate (-90 to 90) |
| `longitude` | decimal | Yes | Longitude coordinate (-180 to 180) |
| `type` | string | Yes | One of: `"selfie"`, `"location"`, `"manual_regularization"` |
| `customer_id` | integer | No | Optional - Customer ID from customers table |
| `selfie_image` | string | No | Optional - Base64 encoded image (with or without data URI prefix) |
| `notes` | string | No | Optional notes (max 1000 characters) |

### Success Response (201 Created)

```json
{
  "success": true,
  "message": "Punched in successfully",
  "data": {
    "id": 123,
    "emp_id": 5,
    "date": "2025-12-16",
    "in_time": "09:30:00",
    "out_time": null,
    "customer_id": 1,
    "selfie_image": "attendance/selfie_in_5_2025-12-16_1734371234.jpg",
    "latitude": "12.97159870",
    "longitude": "77.59456270",
    "type": "location",
    "notes": "Punching in at customer location",
    "created_by": 2,
    "updated_by": 2,
    "created_at": "2025-12-16T09:30:00.000000Z",
    "updated_at": "2025-12-16T09:30:00.000000Z",
    "deleted_at": null,
    "employee": {
      "id": 5,
      "name": "John Doe",
      "employee_code": "EMP001"
    },
    "customer": {
      "id": 1,
      "name": "ABC Company",
      "company_name": "ABC Corporation"
    },
    "creator": {
      "id": 2,
      "name": "Admin User",
      "email": "admin@example.com"
    },
    "updater": {
      "id": 2,
      "name": "Admin User",
      "email": "admin@example.com"
    }
  }
}
```

---

## 2. Punch Out Request

### Request Payload

```json
{
  "action": "punch_out",
  "latitude": 12.9715987,
  "longitude": 77.5945627,
  "type": "location"
}
```

### Minimal Required Payload

```json
{
  "action": "punch_out",
  "latitude": 12.9715987,
  "longitude": 77.5945627,
  "type": "location"
}
```

### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | Must be `"punch_in"` or `"punch_out"` |
| `latitude` | decimal | Yes | Latitude coordinate (-90 to 90) |
| `longitude` | decimal | Yes | Longitude coordinate (-180 to 180) |
| `type` | string | Yes | One of: `"selfie"`, `"location"`, `"manual_regularization"` |
| `customer_id` | integer | No | Optional - Customer ID from customers table |
| `selfie_image` | string | No | Optional - Base64 encoded image (with or without data URI prefix) |
| `notes` | string | No | Optional notes (max 1000 characters) |

### Success Response (200 OK)

```json
{
  "success": true,
  "message": "Punched out successfully",
  "data": {
    "id": 123,
    "emp_id": 5,
    "date": "2025-12-16",
    "in_time": "09:30:00",
    "out_time": "18:00:00",
    "customer_id": 1,
    "selfie_image": "attendance/selfie_out_5_2025-12-16_1734375678.jpg",
    "latitude": "12.97159870",
    "longitude": "77.59456270",
    "type": "location",
    "notes": "Punching out after completing work",
    "created_by": 2,
    "updated_by": 2,
    "created_at": "2025-12-16T09:30:00.000000Z",
    "updated_at": "2025-12-16T18:00:00.000000Z",
    "deleted_at": null,
    "employee": {
      "id": 5,
      "name": "John Doe",
      "employee_code": "EMP001"
    },
    "customer": {
      "id": 1,
      "name": "ABC Company",
      "company_name": "ABC Corporation"
    },
    "creator": {
      "id": 2,
      "name": "Admin User",
      "email": "admin@example.com"
    },
    "updater": {
      "id": 2,
      "name": "Admin User",
      "email": "admin@example.com"
    }
  }
}
```

---

## 3. Error Responses

### Validation Error (422 Unprocessable Entity)

```json
{
  "success": false,
  "message": "Validation errors",
  "errors": {
    "action": ["The action field is required."],
    "latitude": ["The latitude must be a number."],
    "type": ["The selected type is invalid."]
  }
}
```

### No Active Punch In (400 Bad Request) - Punch Out Only

```json
{
  "success": false,
  "message": "No active punch in found for today. Please punch in first."
}
```

### Already Punched In (400 Bad Request) - Punch In Only

```json
{
  "success": false,
  "message": "You already have an active punch in. Please punch out first before punching in again.",
  "data": {
    "open_punch_in": {
      "id": 123,
      "in_time": "09:00:00",
      "date": "2025-12-18"
    }
  }
}
```

### Unauthorized (401 Unauthorized)

```json
{
  "success": false,
  "message": "Employee not found. Please login again."
}
```

### Server Error (500 Internal Server Error)

```json
{
  "success": false,
  "message": "Failed to process punch",
  "error": "Error message details"
}
```

---

## 4. Type Values

The `type` field accepts one of the following values:

- `"selfie"` - Selfie Attendance
- `"location"` - Location Based Attendance
- `"manual_regularization"` - Manual Regularization

---

## 5. Multiple Punches Support

This API supports multiple punch in/out cycles per user per day with proper sequence validation:

### Rules:
- **Punch In**: Only allowed if there's no open punch in (i.e., the last record must be punched out, or no record exists for today)
- **Punch Out**: Only allowed if there's an open punch in (a record with `out_time: null`)
- **Sequence**: Must follow the pattern: `punch_in → punch_out → punch_in → punch_out` (cannot skip steps)

### Example Flow:
1. **Punch In #1** at 09:00 → Creates record with `in_time: 09:00`, `out_time: null`
2. **Punch Out #1** at 12:00 → Updates record #1 with `out_time: 12:00`
3. **Punch In #2** at 13:00 → Creates new record with `in_time: 13:00`, `out_time: null` (allowed because previous record is closed)
4. **Punch Out #2** at 18:00 → Updates record #2 with `out_time: 18:00`
5. **Punch In #3** at 19:00 → Creates new record (allowed because previous record is closed)

### Error Scenarios:
- **Trying to punch in when already punched in**: Returns error "You already have an active punch in. Please punch out first before punching in again."
- **Trying to punch out without punching in**: Returns error "No active punch in found for today. Please punch in first."

---

## 6. Selfie Image Format

The `selfie_image` field accepts base64 encoded images in two formats:

1. **With data URI prefix** (recommended):
   ```
   data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQ...
   ```

2. **Without prefix** (plain base64):
   ```
   /9j/4AAQSkZJRgABAQAAAQ...
   ```

Supported image formats: JPEG, PNG, GIF, etc.

---

## 7. Example cURL Requests

### Punch In (Minimal - Only Required Fields)
```bash
curl -X POST "https://your-domain.com/api/attendance/location-punch" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "punch_in",
    "latitude": 12.9715987,
    "longitude": 77.5945627,
    "type": "location"
  }'
```

### Punch In (With Optional Fields)
```bash
curl -X POST "https://your-domain.com/api/attendance/location-punch" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "punch_in",
    "latitude": 12.9715987,
    "longitude": 77.5945627,
    "type": "location",
    "customer_id": 1,
    "notes": "Starting work at customer site"
  }'
```

### Punch Out (Minimal - Only Required Fields)
```bash
curl -X POST "https://your-domain.com/api/attendance/location-punch" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "punch_out",
    "latitude": 12.9715987,
    "longitude": 77.5945627,
    "type": "location"
  }'
```

### Punch Out (With Optional Fields)
```bash
curl -X POST "https://your-domain.com/api/attendance/location-punch" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "punch_out",
    "latitude": 12.9715987,
    "longitude": 77.5945627,
    "type": "location",
    "notes": "Completed work for the day"
  }'
```

---

## 8. Example JavaScript/Angular Request

```typescript
// Punch In (Minimal - Only Required Fields)
const punchIn = async () => {
  const response = await fetch('https://your-domain.com/api/attendance/location-punch', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      action: 'punch_in',
      latitude: 12.9715987,
      longitude: 77.5945627,
      type: 'location'
    })
  });
  
  const data = await response.json();
  return data;
};

// Punch In (With Optional Fields)
const punchInWithOptional = async () => {
  const response = await fetch('https://your-domain.com/api/attendance/location-punch', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      action: 'punch_in',
      latitude: 12.9715987,
      longitude: 77.5945627,
      type: 'location',
      customer_id: 1, // optional
      selfie_image: base64ImageString, // optional
      notes: 'Punching in at customer location' // optional
    })
  });
  
  const data = await response.json();
  return data;
};

// Punch Out (Minimal - Only Required Fields)
const punchOut = async () => {
  const response = await fetch('https://your-domain.com/api/attendance/location-punch', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      action: 'punch_out',
      latitude: 12.9715987,
      longitude: 77.5945627,
      type: 'location'
    })
  });
  
  const data = await response.json();
  return data;
};

// Punch Out (With Optional Fields)
const punchOutWithOptional = async () => {
  const response = await fetch('https://your-domain.com/api/attendance/location-punch', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      action: 'punch_out',
      latitude: 12.9715987,
      longitude: 77.5945627,
      type: 'location',
      selfie_image: base64ImageString, // optional
      notes: 'Punching out after work' // optional
    })
  });
  
  const data = await response.json();
  return data;
};
```

---

## Notes

- All timestamps are automatically set by the server
- Location coordinates are validated to ensure they are within valid ranges
- Multiple punches per day are supported
- Selfie images are stored in the `storage/app/public/attendance/` directory
- The API automatically loads related data (employee, customer, creator, updater)

