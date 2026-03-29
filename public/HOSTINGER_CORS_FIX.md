# Hostinger CORS Fix - Complete Deployment Guide

## Problem
CORS error: "Response to preflight request doesn't pass access control check: No 'Access-Control-Allow-Origin' header is present"

## Root Cause
The OPTIONS preflight request is not getting CORS headers on Hostinger, even though the config is correct.

## Solution

### Step 1: Upload Updated Files to Hostinger

Upload these files to your Hostinger server:

1. **`public/.htaccess`** - Updated with CORS headers and OPTIONS handling
2. **`config/cors.php`** - Contains production domains
3. **`public/clear_cache_simple.php`** - To clear config cache

### Step 2: Clear Config Cache on Hostinger

**Option A: Use the script (if Composer works)**
- Visit: `https://eb-develop-api.veeyaainnovatives.com/clear_cache_simple.php`
- Check output
- Delete the script after use

**Option B: Manual deletion via cPanel**
1. Login to cPanel
2. Open File Manager
3. Navigate to: `public_html/eb-develop-api/bootstrap/cache/`
4. Delete `config.php` (if it exists)
5. Delete any other `.php` files in that folder (except `.gitignore`)

### Step 3: Verify .htaccess is Working

The updated `.htaccess` now:
- Uses `Header always set` instead of `Header set` (ensures headers are set even for error responses)
- Handles OPTIONS preflight requests BEFORE Laravel routing
- Sets CORS headers for all API routes

### Step 4: Test CORS

1. Open browser console on `https://eb-develop.veeyaainnovatives.com`
2. Make a login request
3. Check Network tab for the OPTIONS preflight request
4. Verify it returns 200 with CORS headers

### Step 5: If Still Not Working

If CORS still fails after these steps:

1. **Check Apache modules:**
   - Ensure `mod_headers` is enabled
   - Ensure `mod_rewrite` is enabled
   - Contact Hostinger support if modules are missing

2. **Check file permissions:**
   - `.htaccess` should be readable (644)
   - `bootstrap/cache/` should be writable (755)

3. **Test OPTIONS request directly:**
   ```bash
   curl -X OPTIONS https://eb-develop-api.veeyaainnovatives.com/api/login \
     -H "Origin: https://eb-develop.veeyaainnovatives.com" \
     -H "Access-Control-Request-Method: POST" \
     -v
   ```
   Should return CORS headers in response

4. **Check Laravel logs:**
   - `storage/logs/laravel.log`
   - Look for any errors related to CORS or middleware

## Files Changed

1. **`public/.htaccess`**
   - Changed `Header set` to `Header always set` (critical for OPTIONS)
   - Added OPTIONS preflight handling before Laravel routing
   - Ensures CORS headers are set even for error responses

2. **`config/cors.php`**
   - Already has production domains configured
   - No changes needed if domains are correct

## Why This Works

1. **`Header always set`**: Ensures CORS headers are sent even for error responses and OPTIONS requests
2. **OPTIONS handling in .htaccess**: Catches OPTIONS requests before Laravel, ensuring they get CORS headers
3. **Config cache cleared**: Forces Laravel to read fresh CORS config from `config/cors.php`

## Verification Checklist

- [ ] `.htaccess` uploaded to `public/` folder
- [ ] Config cache cleared (`bootstrap/cache/config.php` deleted)
- [ ] Test OPTIONS request returns CORS headers
- [ ] Test actual API request works
- [ ] Browser console shows no CORS errors

