# Stock Threshold Notification Implementation Plan

## Current Implementation Status

### ✅ Already Implemented:
1. **StockAlertController** (`app/Http/Controllers/Api/StockAlertController.php`)
   - `getCustomerStockAlerts()` - Gets customer stock alerts based on thresholds
   - `getInternalStockAlerts()` - Gets internal stock alerts based on thresholds
   - `sendEmail()` - Sends email notifications (but needs to be triggered)
   - `createNotificationsFromAlerts()` - Creates dashboard notifications (prevents duplicates within 1 hour)

2. **Email Template** (`resources/views/emails/stock-alerts.blade.php`)
   - Already formatted with customer and internal stock alerts
   - Shows product name, code, current stock, threshold, percentage

3. **Notification System**
   - Creates notifications in database
   - Prevents duplicate notifications within 1 hour

### ❌ What Needs to be Done:

1. **Automatic Email Triggering**
   - Currently email sending is manual (needs API call)
   - Need to trigger automatically when stock goes below threshold
   - Only send when status changes (not on every save)

2. **Email Configuration** ✅ UPDATED
   - Uses mail credentials from `.env` file (MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, etc.)
   - Uses `MAIL_STOCK_ALERT_EMAIL` from `.env` for recipient email (falls back to `MAIL_FROM_ADDRESS`)
   - Email sending uses Laravel Mail with SMTP configuration from `.env`

3. **Change Detection Logic**
   - Need to track previous stock status (above/below threshold)
   - Only send email/notification when crossing threshold (going from above to below)
   - Similar to return status change logic

4. **Trigger Points**
   - When stock availability is saved (`saveAvailabilityWithCustomer`)
   - When stock is updated
   - When stock transfers happen
   - When returns happen

## Implementation Plan

### Step 1: Email Configuration ✅ COMPLETED
- Uses mail credentials from `.env` file (lines 31-40)
- **Recipient email**: Add `MAIL_STOCK_ALERT_EMAIL=your-email@example.com` to `.env` file on **lines 42-43**
- Falls back to `MAIL_FROM_ADDRESS` if `MAIL_STOCK_ALERT_EMAIL` is not set
- SMTP settings: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (from lines 31-40)
- From address: `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` from `.env`

### Step 2: Create Stock Threshold Status Tracker
- Track previous threshold status for each product/customer combination
- Store in cache or database table
- Check if status changed (above → below threshold)

### Step 3: Modify StockAlertController
- Add method to check threshold status change
- Only send email/notification when crossing threshold
- Use existing `createNotificationsFromAlerts()` for dashboard notifications

### Step 4: Add Trigger Points
- In `StockAvailabilityController::saveAvailabilityWithCustomer()` - after saving stock
- In `StockTransferController` - after transfer completes
- In `CustomerReturnController` - after return is processed

### Step 5: Email Content Enhancement
- Include: Stock going to end, Internal/Customer stocks, Product name, Current availability
- Current email template already has most of this

## Questions to Discuss:

1. **Email Configuration**: ✅ RESOLVED - Using `.env` file for mail credentials and `MAIL_STOCK_ALERT_EMAIL` for recipient
2. **Status Tracking**: Where to store previous threshold status? (Cache vs Database)
3. **Notification Frequency**: Send email once per threshold breach or every time it crosses?
4. **Dashboard Notifications**: Current logic prevents duplicates within 1 hour - is this acceptable?
5. **Internal Stock**: How to calculate internal stock availability? (Currently uses `internal_stock_availability` table)

## Next Steps:

1. Review and confirm the approach
2. Implement email configuration setting
3. Add threshold status change detection
4. Integrate triggers at stock save/update points
5. Test email sending and dashboard notifications

