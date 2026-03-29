# Testing OPTIONS Preflight Request

## Current Status
✅ The test script shows CORS headers CAN be set by the server
❌ But OPTIONS preflight requests are still failing

## Test OPTIONS Request

### Method 1: Using Browser Console

Open browser console on `http://localhost:4200` and run:

```javascript
fetch('https://eb-develop-api.veeyaainnovatives.com/api/login', {
  method: 'OPTIONS',
  headers: {
    'Origin': 'http://localhost:4200',
    'Access-Control-Request-Method': 'POST',
    'Access-Control-Request-Headers': 'Content-Type, Authorization'
  }
})
.then(response => {
  console.log('Status:', response.status);
  console.log('Headers:', [...response.headers.entries()]);
  return response.text();
})
.then(text => console.log('Response:', text));
```

**Expected Result:**
- Status: 200
- Headers should include `Access-Control-Allow-Origin: http://localhost:4200`

### Method 2: Using cURL

```bash
curl -X OPTIONS https://eb-develop-api.veeyaainnovatives.com/api/login \
  -H "Origin: http://localhost:4200" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  -v
```

**Expected Output:**
```
< HTTP/1.1 200 OK
< Access-Control-Allow-Origin: http://localhost:4200
< Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH
< Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN
< Access-Control-Allow-Credentials: true
```

### Method 3: Test Script

Visit: `https://eb-develop-api.veeyaainnovatives.com/test_options.php`

Then test OPTIONS from browser console:
```javascript
fetch('https://eb-develop-api.veeyaainnovatives.com/test_options.php', {
  method: 'OPTIONS',
  headers: {
    'Origin': 'http://localhost:4200'
  }
})
.then(r => r.json())
.then(console.log);
```

## If OPTIONS Still Fails

### Check 1: Verify .htaccess is Uploaded
- File should be at: `public_html/eb-develop-api/public/.htaccess`
- Check file contents match the updated version

### Check 2: Verify Apache Modules
The `.htaccess` requires:
- `mod_headers` - for CORS headers
- `mod_rewrite` - for OPTIONS handling

Contact Hostinger support if modules are missing.

### Check 3: Check Apache Error Logs
Look for errors in:
- cPanel → Error Log
- Or: `public_html/eb-develop-api/storage/logs/laravel.log`

### Check 4: Test if Laravel is Intercepting OPTIONS

The issue might be that Laravel's routing is handling OPTIONS before `.htaccess` can set headers.

**Solution:** Make sure the OPTIONS rule in `.htaccess` comes BEFORE the Laravel rewrite rule.

Current `.htaccess` structure:
1. CORS headers (mod_headers)
2. OPTIONS handling (mod_rewrite) ← Should catch OPTIONS first
3. Laravel rewrite rules

## Alternative: Force OPTIONS in Laravel

If `.htaccess` OPTIONS handling doesn't work, we can add explicit OPTIONS handling in Laravel routes.

Add to `routes/api.php`:
```php
Route::options('/{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', request()->header('Origin') ?: '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN')
        ->header('Access-Control-Allow-Credentials', 'true');
})->where('any', '.*');
```

But this should NOT be needed if `.htaccess` is working correctly.

## Next Steps

1. Test OPTIONS request using one of the methods above
2. Share the results (status code, headers)
3. If OPTIONS returns 200 but no CORS headers, the issue is with `.htaccess`
4. If OPTIONS returns 404 or 500, Laravel routing might be interfering

