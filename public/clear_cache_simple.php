<?php
/**
 * Simple Laravel Cache Clear Script (No Composer Required)
 * 
 * This script clears Laravel caches without requiring Composer autoloader
 * Use this when vendor directory is incomplete or Composer is not available
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to public folder on Hostinger
 * 2. Visit: https://eb-develop-api.veeyaainnovatives.com/clear_cache_simple.php
 * 3. Check the output
 * 4. DELETE this file immediately after use (security)
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Laravel Simple Cache Clear Script</h2>";
echo "<hr>";

// Go up one level from public to project root
$basePath = __DIR__.'/..';
$basePath = realpath($basePath);

echo "Base path: " . htmlspecialchars($basePath) . "<br><br>";

// Define cache directories
$cacheDirs = [
    'bootstrap/cache' => 'Bootstrap cache',
    'storage/framework/cache' => 'Application cache',
    'storage/framework/views' => 'View cache',
    'storage/framework/sessions' => 'Session cache',
];

$cleared = [];
$errors = [];

// Clear cache directories
foreach ($cacheDirs as $dir => $name) {
    $fullPath = $basePath . '/' . $dir;
    
    if (is_dir($fullPath)) {
        // Delete all files in cache directory
        $files = glob($fullPath . '/*');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                // Don't delete .gitignore files
                if (basename($file) !== '.gitignore') {
                    if (@unlink($file)) {
                        $deleted++;
                    } else {
                        $errors[] = "Failed to delete: " . htmlspecialchars($file);
                    }
                }
            }
        }
        
        $cleared[] = "✓ {$name}: {$deleted} files deleted";
    } else {
        $errors[] = "Directory not found: {$dir}";
    }
}

// Clear specific cache files (including all possible cache file names)
$cacheFiles = [
    'bootstrap/cache/config.php' => 'Config cache',
    'bootstrap/cache/routes-v7.php' => 'Route cache',
    'bootstrap/cache/routes.php' => 'Route cache (alternative)',
    'bootstrap/cache/services.php' => 'Services cache',
    'bootstrap/cache/packages.php' => 'Packages cache',
    'bootstrap/cache/events.php' => 'Events cache',
];

// Also clear any other cache files in bootstrap/cache
$bootstrapCachePath = $basePath . '/bootstrap/cache';
if (is_dir($bootstrapCachePath)) {
    $allCacheFiles = glob($bootstrapCachePath . '/*.php');
    foreach ($allCacheFiles as $cacheFile) {
        $fileName = basename($cacheFile);
        if ($fileName !== '.gitignore' && !in_array($fileName, ['config.php', 'routes-v7.php', 'routes.php', 'services.php', 'packages.php', 'events.php'])) {
            $cacheFiles[$cacheFile] = 'Additional cache: ' . $fileName;
        }
    }
}

foreach ($cacheFiles as $file => $name) {
    $fullPath = $basePath . '/' . $file;
    
    if (file_exists($fullPath)) {
        if (@unlink($fullPath)) {
            $cleared[] = "✓ {$name}: deleted";
        } else {
            $errors[] = "Failed to delete: {$file}";
        }
    }
}

// Display results
echo "<h3>Cache Clearing Results:</h3>";
foreach ($cleared as $msg) {
    echo "<div style='color: green;'>{$msg}</div>";
}

if (!empty($errors)) {
    echo "<h3 style='color: orange;'>Warnings:</h3>";
    foreach ($errors as $error) {
        echo "<div style='color: orange;'>{$error}</div>";
    }
}

echo "<hr>";
$totalCleared = count($cleared);
echo "<br><strong style='color: green; font-size: 18px;'>✅ Cache clearing completed! Total: {$totalCleared} items cleared</strong><br>";

if (!empty($errors)) {
    echo "<br><strong style='color: orange; font-size: 16px;'>⚠️ Warnings:</strong><br>";
    foreach ($errors as $error) {
        echo "<div style='color: orange; margin-left: 20px;'>• {$error}</div>";
    }
    echo "<br><strong style='color: orange;'>💡 If files couldn't be deleted:</strong><br>";
    echo "<div style='color: orange; margin-left: 20px;'>1. Use cPanel File Manager to delete manually<br>";
    echo "2. Check file permissions (should be 644 for files, 755 for directories)<br>";
    echo "3. Contact Hostinger support if permissions are wrong</div>";
}

echo "<br><strong style='color: blue; font-size: 16px;'>💡 CORS Fix Steps:</strong><br>";
echo "<div style='color: blue; margin-left: 20px;'>1. Verify config/cors.php has your frontend domain<br>";
echo "2. Check browser console for exact CORS error message<br>";
echo "3. Make sure frontend URL matches exactly (including https:// and no trailing slash)<br>";
echo "4. Clear browser cache and try again</div>";

echo "<br><strong style='color: red;'>⚠️ IMPORTANT: Delete this file now for security!</strong>";

