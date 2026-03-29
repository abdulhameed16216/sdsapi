# EB Dashboard - Production Deployment Guide

## 🚀 Production Environment Setup

### 1. Backend Configuration

#### Environment Variables
Copy the production environment file and update the values:

```bash
# Copy the production environment file
cp env.production .env

# Generate application key
php artisan key:generate

# Generate JWT secret
php artisan jwt:secret
```

#### Required Environment Variables to Update:

```env
# Database Configuration
DB_DATABASE=eb_dashboard_production
DB_USERNAME=your_production_db_username
DB_PASSWORD=your_production_db_password

# JWT Secret (generate with: php artisan jwt:secret)
JWT_SECRET=your-generated-jwt-secret

# Application Key (generate with: php artisan key:generate)
APP_KEY=base64:your-generated-app-key

# CORS Origins (add your frontend domain)
CORS_ALLOWED_ORIGINS=https://eb-develop-api.veeyaainnovatives.com,https://your-frontend-domain.com

# Mail Configuration (if using email features)
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email@domain.com
MAIL_PASSWORD=your-email-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="EB Dashboard"
```

### 2. Database Setup

#### Create Production Database
```sql
CREATE DATABASE eb_dashboard_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### Run Migrations
```bash
# Run all migrations
php artisan migrate --force

# Create assignment tables (run the SQL from assignment_tables.sql)
mysql -u your_username -p eb_dashboard_production < assignment_tables.sql

# Seed initial data (if needed)
php artisan db:seed --force
```

### 3. Frontend Configuration

#### Update Environment
The frontend production environment is already configured in `ebms/src/environments/environment.prod.ts` with:
- API Base URL: `https://eb-develop-api.veeyaainnovatives.com/public/api`
- Production optimizations enabled
- Security features enabled

#### Build for Production
```bash
cd ebms

# Install dependencies
npm install

# Build for production
ng build --configuration=production

# The built files will be in ebms/dist/
```

### 4. Server Configuration

#### Apache/Nginx Configuration
Ensure your web server is configured to:
- Point to the `public` directory as document root
- Enable URL rewriting
- Set proper permissions

#### Apache .htaccess (already included in public/.htaccess)
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

#### Nginx Configuration
```nginx
server {
    listen 80;
    server_name eb-develop-api.veeyaainnovatives.com;
    root /path/to/your/project/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Security Configuration

#### File Permissions
```bash
# Set proper permissions
chmod -R 755 storage bootstrap/cache
chmod -R 644 .env
chown -R www-data:www-data storage bootstrap/cache
```

#### SSL Certificate
Ensure SSL certificate is properly configured for HTTPS.

### 6. Performance Optimization

#### Laravel Optimizations
```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev
```

#### Frontend Optimizations
The production build includes:
- Minified JavaScript and CSS
- Tree shaking
- Dead code elimination
- Optimized assets

### 7. Monitoring & Logging

#### Log Configuration
- Logs are stored in `storage/logs/`
- Set `LOG_LEVEL=error` for production
- Configure log rotation

#### Error Monitoring
Consider integrating with services like:
- Sentry (configure `SENTRY_LARAVEL_DSN`)
- Bugsnag
- Rollbar

### 8. Backup Strategy

#### Database Backup
```bash
# Create backup script
mysqldump -u username -p eb_dashboard_production > backup_$(date +%Y%m%d_%H%M%S).sql
```

#### File Backup
```bash
# Backup storage and uploads
tar -czf storage_backup_$(date +%Y%m%d_%H%M%S).tar.gz storage/
```

### 9. API Endpoints

Your production API will be available at:
- Base URL: `https://eb-develop-api.veeyaainnovatives.com/api`
- Authentication: `POST /api/login`
- Dashboard: `GET /api/dashboard/analytics`
- Assignments: `GET /api/assignments`
- And all other endpoints as documented

### 10. Testing Production Setup

#### Health Check
```bash
curl https://eb-develop-api.veeyaainnovatives.com/public/api/health
```

#### Test Authentication
```bash
curl -X POST https://eb-develop-api.veeyaainnovatives.com/public/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"your-email","password":"your-password"}'
```

### 11. Maintenance

#### Regular Tasks
- Monitor disk space
- Check log files
- Update dependencies
- Backup database
- Monitor performance

#### Updates
```bash
# Update Laravel
composer update

# Update frontend dependencies
cd ebms && npm update

# Rebuild frontend
ng build --configuration=production
```

## 🔧 Troubleshooting

### Common Issues

1. **500 Internal Server Error**
   - Check file permissions
   - Verify .env configuration
   - Check Laravel logs

2. **CORS Issues**
   - Update CORS_ALLOWED_ORIGINS in .env
   - Check CORS middleware configuration

3. **Database Connection Issues**
   - Verify database credentials
   - Check database server status
   - Ensure database exists

4. **JWT Token Issues**
   - Verify JWT_SECRET is set
   - Check token expiration settings
   - Verify refresh token configuration

## 📞 Support

For deployment issues or questions, refer to:
- Laravel Documentation: https://laravel.com/docs
- Angular Documentation: https://angular.io/docs
- Your hosting provider's documentation

---

**Production URL**: https://eb-develop-api.veeyaainnovatives.com
**API Base URL**: https://eb-develop-api.veeyaainnovatives.com/public/api
