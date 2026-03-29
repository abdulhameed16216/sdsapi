# Email Alert Configuration Guide

## ✅ Email Alerts Implementation Status

Email alerts have been **successfully implemented** in the system. The following components are in place:

1. ✅ **Email Template**: `resources/views/emails/stock-alerts.blade.php`
2. ✅ **Email Controller**: `app/Http/Controllers/Api/StockAlertController.php` (sendEmail method)
3. ✅ **Mail Configuration**: `config/mail.php`
4. ✅ **API Endpoint**: `POST /api/stock-alerts/send-email`

## 📧 Required Email Configuration

To enable email alerts, you need to configure the following in your `.env` file:

### Basic SMTP Configuration (Gmail Example)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
MAIL_ADMIN_EMAIL=admin@yourdomain.com
```

### Other Email Providers

#### Outlook/Office 365
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=your-email@outlook.com
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@outlook.com
MAIL_FROM_NAME="${APP_NAME}"
MAIL_ADMIN_EMAIL=admin@yourdomain.com
```

#### SendGrid
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your-sendgrid-api-key
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
MAIL_ADMIN_EMAIL=admin@yourdomain.com
```

#### Mailgun
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=your-mailgun-username
MAIL_PASSWORD=your-mailgun-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
MAIL_ADMIN_EMAIL=admin@yourdomain.com
```

#### Custom SMTP Server
```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-server.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
MAIL_ADMIN_EMAIL=admin@yourdomain.com
```

## 🔑 Important Configuration Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `MAIL_MAILER` | Mail driver (smtp, sendmail, log, etc.) | Yes |
| `MAIL_HOST` | SMTP server hostname | Yes (for SMTP) |
| `MAIL_PORT` | SMTP server port (587 for TLS, 465 for SSL) | Yes (for SMTP) |
| `MAIL_USERNAME` | SMTP username | Yes (for SMTP) |
| `MAIL_PASSWORD` | SMTP password or app password | Yes (for SMTP) |
| `MAIL_ENCRYPTION` | Encryption type (tls, ssl, or null) | Recommended |
| `MAIL_FROM_ADDRESS` | Default sender email address | Yes |
| `MAIL_FROM_NAME` | Default sender name | Optional |
| `MAIL_ADMIN_EMAIL` | Email address to receive stock alerts | Yes |

## 📝 Gmail Setup Instructions

If using Gmail, you need to:

1. **Enable 2-Step Verification** on your Google account
2. **Generate an App Password**:
   - Go to Google Account → Security → 2-Step Verification → App passwords
   - Create a new app password for "Mail"
   - Use this app password (not your regular password) in `MAIL_PASSWORD`

3. **Update `.env` file**:
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=xxxx xxxx xxxx xxxx
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="EB Dashboard"
MAIL_ADMIN_EMAIL=admin@yourdomain.com
```

## 🧪 Testing Email Configuration

### Method 1: Test via API
```bash
# Get stock alerts first
curl -X GET http://your-domain.com/api/stock-alerts

# Send email with alerts
curl -X POST http://your-domain.com/api/stock-alerts/send-email \
  -H "Content-Type: application/json" \
  -d '{"alerts": [...]}'
```

### Method 2: Test via Laravel Tinker
```bash
php artisan tinker
```

Then run:
```php
Mail::raw('Test email', function ($message) {
    $message->to('your-email@example.com')
            ->subject('Test Email');
});
```

### Method 3: Use Log Driver for Testing
For development/testing, you can use the log driver to see emails in logs:

```env
MAIL_MAILER=log
```

Emails will be logged to `storage/logs/laravel.log` instead of being sent.

## 🔄 How Email Alerts Work

1. **Alert Detection**: The system automatically detects when stock levels fall below thresholds
2. **Alert Collection**: Alerts are collected via the `/api/stock-alerts` endpoint
3. **Email Sending**: The `/api/stock-alerts/send-email` endpoint sends formatted email notifications
4. **Email Template**: Uses `resources/views/emails/stock-alerts.blade.php` for HTML email formatting

## 📋 Email Alert Content

The email includes:
- **Customer Stock Alerts**: Products below threshold for specific customers
- **Internal Stock Alerts**: Internal products below threshold
- **Alert Levels**: Critical (below 50% of threshold) or Low (below threshold)
- **Product Details**: Product name, code, current stock, threshold, and percentage

## ⚙️ Scheduled Email Alerts (Optional)

To send daily email alerts automatically, you can set up a scheduled task:

### Option 1: Laravel Scheduler

1. Create a command:
```bash
php artisan make:command SendStockAlertsEmail
```

2. Update `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('stock-alerts:email')
             ->daily()
             ->at('09:00');
}
```

3. Add cron job:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Option 2: Direct Cron Job
```bash
0 9 * * * curl -X POST http://your-domain.com/api/stock-alerts/send-email -d '{"alerts":[]}'
```

## 🐛 Troubleshooting

### Email Not Sending

1. **Check Configuration**: Verify all `.env` variables are set correctly
2. **Check Logs**: Review `storage/logs/laravel.log` for errors
3. **Test Connection**: Use `php artisan tinker` to test email sending
4. **Firewall**: Ensure port 587 or 465 is not blocked
5. **Credentials**: Verify username and password are correct

### Common Errors

- **"Connection could not be established"**: Check `MAIL_HOST` and `MAIL_PORT`
- **"Authentication failed"**: Verify `MAIL_USERNAME` and `MAIL_PASSWORD`
- **"Could not instantiate mailer"**: Check `MAIL_MAILER` setting

## 📚 Related Files

- `app/Http/Controllers/Api/StockAlertController.php` - Email sending logic
- `resources/views/emails/stock-alerts.blade.php` - Email template
- `config/mail.php` - Mail configuration
- `ebms/STOCK_ALERT_IMPLEMENTATION.md` - Full implementation documentation

## ✅ Configuration Checklist

- [ ] Update `.env` file with mail configuration
- [ ] Set `MAIL_ADMIN_EMAIL` to receive alerts
- [ ] Test email sending via API or Tinker
- [ ] Verify email template renders correctly
- [ ] Set up scheduled emails (optional)
- [ ] Monitor logs for email errors

---

**Note**: After updating `.env` file, you may need to clear config cache:
```bash
php artisan config:clear
php artisan cache:clear
```

