# CORS Fix - Hostinger Deployment Checklist

## ⚠️ CRITICAL: You're testing from localhost:4200 to production API

The error shows you're running Angular locally (`http://localhost:4200`) but calling the production API (`https://eb-develop-api.veeyaainnovatives.com`).

## ✅ Files Ready for Deployment

1. ✅ `public/.htaccess` - Updated with CORS headers and OPTIONS handling
2. ✅ `config/cors.php` - Has localhost:4200 in allowed origins
3. ✅ `public/clear_cache_simple.php` - Cache clearing script

## 🚀 Steps to Fix on Hostinger

### Step 1: Upload Updated `.htaccess` to Hostinger

**CRITICAL:** Upload the updated `public/.htaccess` file to:
```
public_html/eb-develop-api/public/.htaccess
```

The file MUST be in the `public` folder on Hostinger.

### Step 2: Clear Config Cache on Hostinger

**Option A: Use Script (Recommended)**
1. Upload `public/clear_cache_simple.php` to Hostinger's `public` folder
2. Visit: `https://eb-develop-api.veeyaainnovatives.com/clear_cache_simple.php`
3. Check output - should show cache cleared
4. **DELETE the script immediately after use**

**Option B: Manual via cPanel**
1. Login to cPanel
2. Open File Manager
3. Navigate to: `public_html/eb-develop-api/bootstrap/cache/`
4. Delete `config.php` (if it exists)
5. Delete any other `.php` files (keep `.gitignore`)

### Step 3: Verify `.htaccess` is Working

Test the OPTIONS request directly:
```bash
curl -X OPTIONS https://eb-develop-api.veeyaainnovatives.com/api/login \
  -H "Origin: http://localhost:4200" \
  -H "Access-Control-Request-Method: POST" \
  -v
```

You should see:
```
< HTTP/1.1 200 OK
< Access-Control-Allow-Origin: http://localhost:4200
< Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH
< Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN
< Access-Control-Allow-Credentials: true
```

### Step 4: Test from Your Local Angular App

1. Clear browser cache (Ctrl+Shift+R)
2. Try login again from `http://localhost:4200`
3. Check browser Network tab:
   - Look for OPTIONS request to `/api/login`
   - Should return 200 with CORS headers
   - Then POST request should succeed

## 🔍 Troubleshooting

### If CORS still fails after uploading `.htaccess`:

1. **Check Apache modules on Hostinger:**
   - `mod_headers` must be enabled
   - `mod_rewrite` must be enabled
   - Contact Hostinger support if modules are missing

2. **Check file permissions:**
   - `.htaccess` should be readable (644)
   - `bootstrap/cache/` should be writable (755)

3. **Verify `.htaccess` is in correct location:**
   - Should be in: `public_html/eb-develop-api/public/.htaccess`
   - NOT in: `public_html/eb-develop-api/.htaccess`

4. **Check if Laravel is reading config:**
   - Visit: `https://eb-develop-api.veeyaainnovatives.com/test_cors_headers.php`
   - Should show CORS headers in response

## 📝 What Changed in `.htaccess`

1. **`Header always set`** instead of `Header set`
   - Ensures headers are sent even for error responses
   - Critical for OPTIONS preflight requests

2. **OPTIONS handling before Laravel routing**
   - Catches OPTIONS requests early
   - Returns 200 with CORS headers immediately

3. **`localhost:4200` in allowed origins**
   - Already configured in both `.htaccess` and `config/cors.php`

## ✅ Verification Checklist

- [ ] `.htaccess` uploaded to `public/` folder on Hostinger
- [ ] Config cache cleared (`bootstrap/cache/config.php` deleted)
- [ ] Test OPTIONS request returns CORS headers (use curl command above)
- [ ] Test from localhost:4200 - login should work
- [ ] Browser console shows no CORS errors

## 🎯 Expected Result

After completing these steps:
- OPTIONS preflight request should return 200 with CORS headers
- POST request to `/api/login` should succeed
- No CORS errors in browser console

