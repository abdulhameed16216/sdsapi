<?php
/**
 * FORCE FIX CORS - Works even if cache can't be deleted
 * 
 * This script will:
 * 1. Try to delete config cache
 * 2. Create a temporary route to test CORS
 * 3. Provide manual fix instructions
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to public folder on Hostinger
 * 2. Visit: https://eb-develop-api.veeyaainnovatives.com/force_fix_cors.php
 * 3. Follow ALL instructions shown
 * 4. DELETE this file immediately after use (security)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set CORS headers FIRST before any output
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowedOrigins = [
    'https://eb-develop.veeyaainnovatives.com',
    'https://eb-develop-api.veeyaainnovatives.com',
    'http://localhost:3000',
    'http://localhost:4200',
    'http://localhost:8100',
];

if ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://eb-develop.veeyaainnovatives.com');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>CORS Force Fix</title>";
echo "<style>body{font-family:Arial,sans-serif;max-width:900px;margin:20px auto;padding:20px;background:#f5f5f5;}";
echo ".success{background:#d4edda;border:1px solid #c3e6cb;padding:15px;margin:10px 0;border-radius:5px;}";
echo ".error{background:#f8d7da;border:1px solid #f5c6cb;padding:15px;margin:10px 0;border-radius:5px;}";
echo ".warning{background:#fff3cd;border:1px solid #ffeaa7;padding:15px;margin:10px 0;border-radius:5px;}";
echo ".info{background:#d1ecf1;border:1px solid #bee5eb;padding:15px;margin:10px 0;border-radius:5px;}";
echo ".code{background:#f8f9fa;border:1px solid #dee2e6;padding:10px;font-family:monospace;margin:10px 0;border-radius:5px;overflow-x:auto;}";
echo "h2{color:#333;}h3{color:#555;margin-top:20px;}strong{color:#d9534f;}</style></head><body>";

echo "<h2>🔧 CORS Force Fix Script</h2>";
echo "<hr>";

$basePath = __DIR__.'/..';
$basePath = realpath($basePath);

echo "<div class='info'><strong>Base path:</strong> " . htmlspecialchars($basePath) . "</div>";

$issues = [];
$solutions = [];

// Step 1: Check and delete config cache
echo "<h3>Step 1: Config Cache</h3>";
$configCacheFile = $basePath . '/bootstrap/cache/config.php';
if (file_exists($configCacheFile)) {
    // Try to delete
    if (@unlink($configCacheFile)) {
        echo "<div class='success'>✅ Config cache deleted successfully!</div>";
    } else {
        $issues[] = "Config cache exists but cannot be deleted";
        $solutions[] = "MANUAL FIX: Use cPanel File Manager → Navigate to <code>bootstrap/cache/</code> → Delete <code>config.php</code>";
        echo "<div class='error'><strong>❌ CRITICAL:</strong> Config cache exists but cannot be deleted automatically!</div>";
        echo "<div class='warning'><strong>File path:</strong> <code>" . htmlspecialchars($configCacheFile) . "</code></div>";
        echo "<div class='warning'><strong>File permissions:</strong> " . substr(sprintf('%o', fileperms($configCacheFile)), -4) . "</div>";
    }
} else {
    echo "<div class='success'>✅ No config cache found (good!)</div>";
}

// Step 2: Verify config/cors.php
echo "<h3>Step 2: CORS Configuration File</h3>";
$configFile = $basePath . '/config/cors.php';
if (file_exists($configFile)) {
    $configContent = file_get_contents($configFile);
    
    // Check for production domain
    if (strpos($configContent, 'https://eb-develop.veeyaainnovatives.com') !== false) {
        echo "<div class='success'>✅ Production domain found in config</div>";
    } else {
        $issues[] = "Production domain missing from config";
        $solutions[] = "Add 'https://eb-develop.veeyaainnovatives.com' to allowed_origins in config/cors.php";
        echo "<div class='error'><strong>❌ Production domain NOT found!</strong></div>";
    }
    
    // Show current config
    echo "<div class='code'><strong>Current config/cors.php allowed_origins:</strong><br>";
    if (preg_match("/'allowed_origins'\s*=>\s*\[(.*?)\]/s", $configContent, $matches)) {
        $origins = $matches[1];
        $originLines = explode("\n", $origins);
        foreach ($originLines as $line) {
            $line = trim($line);
            if (preg_match("/['\"](.*?)['\"]/", $line, $originMatch)) {
                echo "  • " . htmlspecialchars($originMatch[1]) . "<br>";
            }
        }
    }
    echo "</div>";
} else {
    $issues[] = "config/cors.php not found";
    $solutions[] = "Create config/cors.php file with proper CORS settings";
    echo "<div class='error'><strong>❌ config/cors.php not found!</strong></div>";
}

// Step 3: Check file permissions
echo "<h3>Step 3: File Permissions</h3>";
$bootstrapCacheDir = $basePath . '/bootstrap/cache';
if (is_dir($bootstrapCacheDir)) {
    $perms = substr(sprintf('%o', fileperms($bootstrapCacheDir)), -4);
    echo "<div class='info'><strong>bootstrap/cache permissions:</strong> {$perms}</div>";
    if ($perms !== '0755' && $perms !== '0775' && $perms !== '0777') {
        echo "<div class='warning'>⚠️ Directory permissions might be too restrictive. Should be 755 or 775</div>";
    }
} else {
    echo "<div class='error'>❌ bootstrap/cache directory not found!</div>";
}

// Step 4: Test CORS headers
echo "<h3>Step 4: CORS Headers Test</h3>";
$testOrigin = $origin ?: 'https://eb-develop.veeyaainnovatives.com';
echo "<div class='info'>";
echo "<strong>Request Origin:</strong> " . htmlspecialchars($origin ?? 'Not set') . "<br>";
echo "<strong>Test Origin:</strong> " . htmlspecialchars($testOrigin) . "<br>";
echo "<strong>CORS Headers Set:</strong><br>";
echo "  • Access-Control-Allow-Origin: " . htmlspecialchars($testOrigin) . "<br>";
echo "  • Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH<br>";
echo "  • Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN<br>";
echo "  • Access-Control-Allow-Credentials: true<br>";
echo "</div>";

// Step 5: Manual Fix Instructions
echo "<h3>Step 5: Manual Fix Instructions (if needed)</h3>";

if (!empty($issues)) {
    echo "<div class='error'><strong>⚠️ Issues Found:</strong><br>";
    foreach ($issues as $issue) {
        echo "  • {$issue}<br>";
    }
    echo "</div>";
    
    echo "<div class='warning'><strong>🔧 Solutions:</strong><br>";
    foreach ($solutions as $i => $solution) {
        echo "<strong>" . ($i + 1) . ".</strong> {$solution}<br><br>";
    }
    echo "</div>";
}

echo "<div class='info'><strong>📋 Complete Manual Fix Steps:</strong><br><br>";
echo "<strong>1. Delete Config Cache (CRITICAL):</strong><br>";
echo "   • Login to cPanel<br>";
echo "   • Go to File Manager<br>";
echo "   • Navigate to: <code>public_html/eb-develop-api/bootstrap/cache/</code><br>";
echo "   • Delete file: <code>config.php</code> (if it exists)<br>";
echo "   • Also delete any other .php files in that folder<br><br>";

echo "<strong>2. Verify config/cors.php:</strong><br>";
echo "   • File: <code>public_html/eb-develop-api/config/cors.php</code><br>";
echo "   • Make sure it contains:<br>";
echo "   <div class='code'>'allowed_origins' => [<br>";
echo "       'https://eb-develop.veeyaainnovatives.com',<br>";
echo "       'https://eb-develop-api.veeyaainnovatives.com',<br>";
echo "   ],</div><br>";

echo "<strong>3. Set File Permissions:</strong><br>";
echo "   • <code>bootstrap/cache/</code> folder: 755<br>";
echo "   • <code>config/cors.php</code> file: 644<br><br>";

echo "<strong>4. Test CORS:</strong><br>";
echo "   • Open browser console on your frontend<br>";
echo "   • Make an API request<br>";
echo "   • Check for CORS errors<br>";
echo "   • Share the exact error message if it still fails<br><br>";

echo "<strong>5. Alternative: Use .htaccess (if Laravel CORS still fails):</strong><br>";
echo "   • Add to <code>public/.htaccess</code> (BEFORE the Laravel rewrite rules):<br>";
echo "   <div class='code'>&lt;IfModule mod_headers.c&gt;<br>";
echo "   Header set Access-Control-Allow-Origin \"https://eb-develop.veeyaainnovatives.com\"<br>";
echo "   Header set Access-Control-Allow-Methods \"GET, POST, PUT, DELETE, OPTIONS, PATCH\"<br>";
echo "   Header set Access-Control-Allow-Headers \"Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN\"<br>";
echo "   Header set Access-Control-Allow-Credentials \"true\"<br>";
echo "   &lt;/IfModule&gt;</div>";
echo "</div>";

// Step 6: Create .htaccess backup solution
echo "<h3>Step 6: Quick .htaccess Fix (Alternative)</h3>";
$htaccessFile = $basePath . '/public/.htaccess';
if (file_exists($htaccessFile)) {
    $htaccessContent = file_get_contents($htaccessFile);
    
    // Check if CORS headers already exist
    if (strpos($htaccessContent, 'Access-Control-Allow-Origin') === false) {
        echo "<div class='warning'><strong>💡 Quick Fix:</strong> Add CORS headers to .htaccess</div>";
        echo "<div class='code'>Add this at the TOP of public/.htaccess (before &lt;IfModule mod_rewrite.c&gt;):<br><br>";
        echo "&lt;IfModule mod_headers.c&gt;<br>";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;SetEnvIf Origin \"^https://eb-develop\\.veeyaainnovatives\\.com$\" CORS=1<br>";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;Header set Access-Control-Allow-Origin \"%{CORS}e\" env=CORS<br>";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;Header set Access-Control-Allow-Methods \"GET, POST, PUT, DELETE, OPTIONS, PATCH\" env=CORS<br>";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;Header set Access-Control-Allow-Headers \"Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN\" env=CORS<br>";
        echo "&nbsp;&nbsp;&nbsp;&nbsp;Header set Access-Control-Allow-Credentials \"true\" env=CORS<br>";
        echo "&lt;/IfModule&gt;</div>";
    } else {
        echo "<div class='info'>✅ .htaccess already has CORS headers</div>";
    }
} else {
    echo "<div class='warning'>⚠️ .htaccess file not found</div>";
}

echo "<hr>";
echo "<div class='success'><strong>✅ Script completed!</strong></div>";
echo "<div class='error'><strong>⚠️ IMPORTANT: Delete this file (force_fix_cors.php) now for security!</strong></div>";

echo "</body></html>";

