<?php
/**
 * CORS Configuration Checker
 * 
 * This script checks your CORS configuration and shows what's actually being used
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to public folder on Hostinger
 * 2. Visit: https://eb-develop-api.veeyaainnovatives.com/check_cors.php
 * 3. Review the output
 * 4. DELETE this file immediately after use (security)
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>CORS Configuration Checker</h2>";
echo "<hr>";

// Go up one level from public to project root
$basePath = __DIR__.'/..';
$basePath = realpath($basePath);

echo "Base path: " . htmlspecialchars($basePath) . "<br><br>";

// Check if config file exists
$configFile = $basePath . '/config/cors.php';
echo "<h3>1. Checking config/cors.php file:</h3>";
if (file_exists($configFile)) {
    echo "  ✅ File exists<br>";
    $configContent = file_get_contents($configFile);
    
    // Extract allowed origins
    if (preg_match("/'allowed_origins'\s*=>\s*\[(.*?)\]/s", $configContent, $matches)) {
        echo "  <strong>Allowed Origins in config file:</strong><br>";
        $origins = $matches[1];
        $originLines = explode("\n", $origins);
        foreach ($originLines as $line) {
            $line = trim($line);
            if (preg_match("/['\"](.*?)['\"]/", $line, $originMatch)) {
                echo "    • " . htmlspecialchars($originMatch[1]) . "<br>";
            }
        }
    } else {
        echo "  ⚠️ Could not parse allowed_origins from config file<br>";
    }
} else {
    echo "  ❌ File not found!<br>";
}

// Check if config cache exists
$configCacheFile = $basePath . '/bootstrap/cache/config.php';
echo "<br><h3>2. Checking config cache:</h3>";
if (file_exists($configCacheFile)) {
    echo "  ⚠️ <strong style='color: red;'>Config cache EXISTS - This might be using old CORS settings!</strong><br>";
    echo "  💡 <strong>Solution: Delete bootstrap/cache/config.php</strong><br>";
    
    // Try to read cached config
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configCacheFile);
    }
    
    try {
        $cachedConfig = include $configCacheFile;
        if (isset($cachedConfig['cors']['allowed_origins'])) {
            echo "  <strong>Cached Allowed Origins:</strong><br>";
            foreach ($cachedConfig['cors']['allowed_origins'] as $origin) {
                echo "    • " . htmlspecialchars($origin) . "<br>";
            }
        }
    } catch (Exception $e) {
        echo "  ⚠️ Could not read cached config: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
} else {
    echo "  ✅ No config cache - Laravel will read directly from config/cors.php<br>";
}

// Check .env file
$envFile = $basePath . '/.env';
echo "<br><h3>3. Checking .env file:</h3>";
if (file_exists($envFile)) {
    echo "  ✅ .env file exists<br>";
    $envContent = file_get_contents($envFile);
    
    if (preg_match('/CORS_ALLOWED_ORIGINS=(.*)/', $envContent, $envMatch)) {
        $envOrigins = trim($envMatch[1]);
        echo "  <strong>CORS_ALLOWED_ORIGINS in .env:</strong> " . htmlspecialchars($envOrigins) . "<br>";
    } else {
        echo "  ⚠️ CORS_ALLOWED_ORIGINS not found in .env<br>";
    }
} else {
    echo "  ⚠️ .env file not found<br>";
}

// Test CORS headers
echo "<br><h3>4. Testing CORS Headers:</h3>";
echo "  <strong>Current Request Origin:</strong> " . htmlspecialchars($_SERVER['HTTP_ORIGIN'] ?? 'Not set') . "<br>";
echo "  <strong>Current Request Method:</strong> " . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'Not set') . "<br>";

// Show what should be in allowed origins
echo "<br><h3>5. Recommended Allowed Origins:</h3>";
echo "  <div style='background: #f0f0f0; padding: 10px; border-left: 4px solid #007bff;'>";
echo "    <strong>For Production:</strong><br>";
echo "    • https://eb-develop.veeyaainnovatives.com<br>";
echo "    • https://eb-develop-api.veeyaainnovatives.com<br>";
echo "    <br>";
echo "    <strong>For Development (if needed):</strong><br>";
echo "    • http://localhost:3000<br>";
echo "    • http://localhost:4200<br>";
echo "    • http://localhost:8100<br>";
echo "  </div>";

// Common CORS issues
echo "<br><h3>6. Common CORS Issues to Check:</h3>";
echo "  <div style='background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;'>";
echo "    ✓ Make sure frontend URL matches EXACTLY (including https://)<br>";
echo "    ✓ No trailing slash in allowed origins (https://example.com NOT https://example.com/)<br>";
echo "    ✓ Check browser console for exact error message<br>";
echo "    ✓ Verify CORS middleware is enabled in bootstrap/app.php<br>";
echo "    ✓ Clear browser cache after updating CORS settings<br>";
echo "  </div>";

echo "<hr>";
echo "<br><strong style='color: red;'>⚠️ IMPORTANT: Delete this file now for security!</strong>";

