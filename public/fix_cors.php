<?php
/**
 * CORS Fix Script
 * 
 * This script will:
 * 1. Delete config cache
 * 2. Verify CORS configuration
 * 3. Test CORS headers
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to public folder on Hostinger
 * 2. Visit: https://eb-develop-api.veeyaainnovatives.com/fix_cors.php
 * 3. Follow the instructions
 * 4. DELETE this file immediately after use (security)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>CORS Fix Script</h2>";
echo "<hr>";

$basePath = __DIR__.'/..';
$basePath = realpath($basePath);

echo "Base path: " . htmlspecialchars($basePath) . "<br><br>";

$fixed = [];
$errors = [];

// Step 1: Delete config cache
echo "<h3>Step 1: Clearing Config Cache</h3>";
$configCacheFile = $basePath . '/bootstrap/cache/config.php';
if (file_exists($configCacheFile)) {
    if (@unlink($configCacheFile)) {
        $fixed[] = "✅ Config cache deleted";
        echo "  ✅ <strong>Config cache deleted successfully!</strong><br><br>";
    } else {
        $errors[] = "Could not delete config cache - delete manually via cPanel";
        echo "  ⚠️ <strong style='color: red;'>Could not delete config cache</strong><br>";
        echo "  💡 <strong>Manual fix: Delete bootstrap/cache/config.php via cPanel File Manager</strong><br><br>";
    }
} else {
    $fixed[] = "✅ No config cache found (already cleared)";
    echo "  ✅ No config cache found<br><br>";
}

// Step 2: Verify config/cors.php
echo "<h3>Step 2: Verifying config/cors.php</h3>";
$configFile = $basePath . '/config/cors.php';
if (file_exists($configFile)) {
    $configContent = file_get_contents($configFile);
    
    // Check if it has the production domains
    $hasProductionDomain = false;
    if (strpos($configContent, 'https://eb-develop.veeyaainnovatives.com') !== false) {
        $hasProductionDomain = true;
        echo "  ✅ Production domain found in config<br>";
    } else {
        echo "  ⚠️ <strong style='color: orange;'>Production domain NOT found in config!</strong><br>";
        echo "  💡 Add 'https://eb-develop.veeyaainnovatives.com' to allowed_origins<br>";
    }
    
    // Check if it's using hardcoded values or env
    if (strpos($configContent, "env('CORS_ALLOWED_ORIGINS'") !== false) {
        echo "  ℹ️ Config reads from .env file<br>";
    } else {
        echo "  ℹ️ Config uses hardcoded values<br>";
    }
    
    echo "<br>";
} else {
    $errors[] = "config/cors.php not found!";
    echo "  ❌ <strong style='color: red;'>config/cors.php not found!</strong><br><br>";
}

// Step 3: Check .htaccess for CORS headers (if needed)
echo "<h3>Step 3: Checking .htaccess</h3>";
$htaccessFile = $basePath . '/public/.htaccess';
if (file_exists($htaccessFile)) {
    $htaccessContent = file_get_contents($htaccessFile);
    if (strpos($htaccessContent, 'Access-Control-Allow-Origin') !== false) {
        echo "  ⚠️ .htaccess has CORS headers - this might conflict with Laravel CORS<br>";
        echo "  💡 Remove CORS headers from .htaccess, let Laravel handle it<br>";
    } else {
        echo "  ✅ .htaccess doesn't have CORS headers (good)<br>";
    }
    echo "<br>";
} else {
    echo "  ℹ️ .htaccess not found (not required)<br><br>";
}

// Step 4: Test CORS headers
echo "<h3>Step 4: Testing CORS Headers</h3>";
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin) {
    echo "  Request Origin: <strong>" . htmlspecialchars($origin) . "</strong><br>";
    
    // Check if origin is in allowed list
    $allowedOrigins = [
        'https://eb-develop.veeyaainnovatives.com',
        'https://eb-develop-api.veeyaainnovatives.com',
        'http://localhost:3000',
        'http://localhost:4200',
        'http://localhost:8100',
    ];
    
    if (in_array($origin, $allowedOrigins)) {
        echo "  ✅ Origin is in allowed list<br>";
    } else {
        echo "  ⚠️ <strong style='color: orange;'>Origin NOT in allowed list!</strong><br>";
        echo "  💡 Add this origin to config/cors.php allowed_origins<br>";
    }
} else {
    echo "  ℹ️ No Origin header in request (direct access)<br>";
}
echo "<br>";

// Step 5: Set CORS headers for this response
echo "<h3>Step 5: Setting Test CORS Headers</h3>";
$testOrigin = $origin ?: 'https://eb-develop.veeyaainnovatives.com';
header('Access-Control-Allow-Origin: ' . $testOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo "  ✅ CORS headers set for this response<br>";
echo "  Access-Control-Allow-Origin: <strong>" . htmlspecialchars($testOrigin) . "</strong><br><br>";

// Step 6: Recommendations
echo "<h3>Step 6: Recommendations</h3>";
echo "<div style='background: #e7f3ff; padding: 15px; border-left: 4px solid #2196F3; margin: 10px 0;'>";
echo "<strong>✅ What to do next:</strong><br><br>";
echo "1. <strong>Verify config/cors.php</strong> has these origins:<br>";
echo "   • https://eb-develop.veeyaainnovatives.com<br>";
echo "   • https://eb-develop-api.veeyaainnovatives.com<br><br>";

echo "2. <strong>If config cache exists and can't be deleted:</strong><br>";
echo "   • Use cPanel File Manager<br>";
echo "   • Navigate to bootstrap/cache/<br>";
echo "   • Delete config.php file<br><br>";

echo "3. <strong>Test from your frontend:</strong><br>";
echo "   • Open browser console<br>";
echo "   • Make an API request<br>";
echo "   • Check for CORS errors<br>";
echo "   • Share the exact error message<br><br>";

echo "4. <strong>Clear browser cache:</strong><br>";
echo "   • Hard refresh (Ctrl+Shift+R or Cmd+Shift+R)<br>";
echo "   • Or clear browser cache completely<br><br>";

echo "5. <strong>Check browser console error:</strong><br>";
echo "   • Look for exact error message<br>";
echo "   • Check which origin is being blocked<br>";
echo "   • Verify the origin matches exactly (no trailing slash)<br>";
echo "</div>";

echo "<hr>";
echo "<br><strong style='color: green; font-size: 18px;'>✅ CORS Fix Script Completed</strong><br>";

if (!empty($errors)) {
    echo "<br><strong style='color: orange;'>⚠️ Issues Found:</strong><br>";
    foreach ($errors as $error) {
        echo "<div style='color: orange; margin-left: 20px;'>• {$error}</div>";
    }
}

echo "<br><strong style='color: red;'>⚠️ IMPORTANT: Delete this file now for security!</strong>";

