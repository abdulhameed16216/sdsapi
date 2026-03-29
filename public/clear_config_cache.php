<?php
/**
 * Clear Laravel Config Cache - Simple Version
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to public folder on Hostinger
 * 2. Visit: https://eb-develop-api.veeyaainnovatives.com/clear_config_cache.php
 * 3. Check the output
 * 4. DELETE this file immediately after use (security)
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Laravel Cache Clear Script</h2>";
echo "<hr>";

// Go up one level from public to project root
$basePath = __DIR__.'/..';
$basePath = realpath($basePath);

echo "Base path: " . htmlspecialchars($basePath) . "<br>";

// Check if vendor exists - but don't fail if it's incomplete
if (!file_exists($basePath.'/vendor/autoload.php')) {
    echo "<strong style='color: orange;'>⚠️ Warning: vendor/autoload.php not found. Using simple cache clear method...</strong><br>";
    echo "<br>For full Laravel cache clearing, please run 'composer install' on the server.<br>";
    echo "<br>Using alternative method to clear caches...<br><br>";
    
    // Use simple file deletion method
    clearCacheSimple($basePath);
    exit;
}

echo "✓ vendor/autoload.php found<br>";

try {
    require $basePath.'/vendor/autoload.php';
    echo "✓ Autoloader loaded<br>";
} catch (Exception $e) {
    echo "<strong style='color: orange;'>⚠️ Warning: Error loading autoloader: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
    echo "<br>Falling back to simple cache clear method...<br><br>";
    
    // Use simple file deletion method
    clearCacheSimple($basePath);
    exit;
} catch (Throwable $e) {
    echo "<strong style='color: orange;'>⚠️ Warning: Fatal error loading autoloader: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
    echo "<br>Falling back to simple cache clear method...<br><br>";
    
    // Use simple file deletion method
    clearCacheSimple($basePath);
    exit;
}

// Simple cache clearing function (fallback)
function clearCacheSimple($basePath) {
    $cacheDirs = [
        'bootstrap/cache' => 'Bootstrap cache',
        'storage/framework/cache' => 'Application cache',
        'storage/framework/views' => 'View cache',
        'storage/framework/sessions' => 'Session cache',
    ];
    
    // Get all cache files from bootstrap/cache
    $cacheFiles = [];
    $bootstrapCachePath = $basePath . '/bootstrap/cache';
    if (is_dir($bootstrapCachePath)) {
        $allCacheFiles = glob($bootstrapCachePath . '/*.php');
        foreach ($allCacheFiles as $cacheFile) {
            $fileName = basename($cacheFile);
            if ($fileName !== '.gitignore') {
                $cacheFiles[$cacheFile] = 'Cache: ' . $fileName;
            }
        }
    }
    
    // Also add specific known cache files
    $knownCacheFiles = [
        'bootstrap/cache/config.php' => 'Config cache',
        'bootstrap/cache/routes-v7.php' => 'Route cache',
        'bootstrap/cache/routes.php' => 'Route cache (alt)',
        'bootstrap/cache/services.php' => 'Services cache',
        'bootstrap/cache/packages.php' => 'Packages cache',
        'bootstrap/cache/events.php' => 'Events cache',
    ];
    
    foreach ($knownCacheFiles as $file => $name) {
        $fullPath = $basePath . '/' . $file;
        if (!isset($cacheFiles[$fullPath])) {
            $cacheFiles[$fullPath] = $name;
        }
    }
    
    echo "<h3>Clearing Caches (Simple Method)...</h3>";
    
    $totalDeleted = 0;
    $errors = [];
    
    // CRITICAL: Clear bootstrap/cache/config.php first (contains CORS settings)
    $configCacheFile = $basePath . '/bootstrap/cache/config.php';
    echo "<strong style='color: red;'>🔴 CRITICAL: Checking config cache file...</strong><br>";
    if (file_exists($configCacheFile)) {
        if (@unlink($configCacheFile)) {
            echo "  ✅ <strong>Config cache (config.php) DELETED - CORS settings will reload!</strong><br><br>";
            $totalDeleted++;
        } else {
            $errors[] = "CRITICAL: Could not delete config.php - check file permissions (should be 644)";
            echo "  ⚠️ <strong style='color: red;'>FAILED to delete config.php - This is blocking CORS updates!</strong><br>";
            echo "  💡 <strong>Solution: Delete manually via cPanel File Manager</strong><br><br>";
        }
    } else {
        echo "  ✓ Config cache file doesn't exist (already cleared or never cached)<br><br>";
    }
    
    // Clear all cache directories
    foreach ($cacheDirs as $dir => $name) {
        $fullPath = $basePath . '/' . $dir;
        if (is_dir($fullPath)) {
            $files = glob($fullPath . '/*');
            $deleted = 0;
            $found = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    $found++;
                    $fileName = basename($file);
                    if ($fileName !== '.gitignore') {
                        if (@unlink($file)) {
                            $deleted++;
                            echo "  ✓ Deleted: {$fileName}<br>";
                        } else {
                            $errors[] = "Could not delete: {$file}";
                            echo "  ⚠️ Could not delete: {$fileName}<br>";
                        }
                    }
                }
            }
            if ($found > 0) {
                echo "<strong>{$name}: {$deleted} of {$found} files deleted</strong><br><br>";
            } else {
                echo "{$name}: No files found (directory empty)<br><br>";
            }
            $totalDeleted += $deleted;
        } else {
            echo "⚠️ Directory not found: {$dir}<br><br>";
        }
    }
    
    // Clear specific known cache files
    echo "<br><strong>Checking additional cache files:</strong><br>";
    foreach ($cacheFiles as $file => $name) {
        $fullPath = is_string($file) && strpos($file, $basePath) === 0 ? $file : $basePath . '/' . $file;
        if (file_exists($fullPath)) {
            if (@unlink($fullPath)) {
                echo "  ✓ {$name}: deleted<br>";
                $totalDeleted++;
            } else {
                $errors[] = "Could not delete: {$file}";
                echo "  ⚠️ {$name}: could not delete<br>";
            }
        }
    }
    
    echo "<hr>";
    echo "<br><strong style='color: green; font-size: 18px;'>✅ Cache clearing completed! Total: {$totalDeleted} files deleted</strong><br>";
    
    if (!empty($errors)) {
        echo "<br><strong style='color: orange; font-size: 16px;'>⚠️ Warnings:</strong><br>";
        foreach ($errors as $error) {
            echo "<div style='color: orange; margin-left: 20px;'>• {$error}</div>";
        }
        echo "<br><strong style='color: orange;'>💡 If files couldn't be deleted:</strong><br>";
        echo "<div style='color: orange; margin-left: 20px;'>1. Use cPanel File Manager to delete manually<br>";
        echo "2. Check file permissions (should be 644 for files)<br>";
        echo "3. Contact Hostinger support if permissions are wrong</div>";
    }
    
    echo "<br><strong style='color: blue; font-size: 16px;'>💡 Next Steps:</strong><br>";
    echo "<div style='color: blue; margin-left: 20px;'>1. Verify config/cors.php has correct origins<br>";
    echo "2. If CORS still fails, check browser console for exact error<br>";
    echo "3. Make sure your frontend URL matches exactly in allowed_origins</div>";
    
    echo "<br><strong style='color: red;'>⚠️ IMPORTANT: Delete this file now for security!</strong>";
}

try {
    $app = require_once $basePath.'/bootstrap/app.php';
    echo "✓ Laravel app loaded<br>";
} catch (Exception $e) {
    die("<strong style='color: red;'>❌ Error loading Laravel app: " . htmlspecialchars($e->getMessage()) . "</strong>");
}

try {
    $kernel = $app->make('Illuminate\Contracts\Console\Kernel');
    $kernel->bootstrap();
    echo "✓ Kernel bootstrapped<br>";
} catch (Exception $e) {
    die("<strong style='color: red;'>❌ Error bootstrapping kernel: " . htmlspecialchars($e->getMessage()) . "</strong>");
}

echo "<hr>";
echo "<h3>Clearing Caches...</h3>";

try {
    // Clear all caches using optimize:clear (clears config, route, view, compiled, events)
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    echo "✓ All optimized caches cleared (config, route, view, compiled, events)<br>";
    
    // Clear application cache
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    echo "✓ Application cache cleared<br>";
    
    // Clear config cache (explicit, most important for CORS)
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    echo "✓ Config cache cleared (CORS settings will reload)<br>";
    
    // Clear route cache
    \Illuminate\Support\Facades\Artisan::call('route:clear');
    echo "✓ Route cache cleared<br>";
    
    // Clear view cache
    \Illuminate\Support\Facades\Artisan::call('view:clear');
    echo "✓ View cache cleared<br>";
    
    // Clear event cache
    \Illuminate\Support\Facades\Artisan::call('event:clear');
    echo "✓ Event cache cleared<br>";
    
    echo "<hr>";
    echo "<br><strong style='color: green; font-size: 18px;'>✅ All Laravel caches cleared successfully!</strong><br>";
    echo "<br><strong style='color: blue;'>💡 Note: Config cache was cleared. Laravel will read fresh settings from config files.</strong><br>";
    echo "<br><strong style='color: red;'>⚠️ IMPORTANT: Delete this file now for security!</strong>";
    
} catch (Exception $e) {
    echo "<hr>";
    echo "<strong style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
    echo "<strong>File:</strong> " . htmlspecialchars($e->getFile()) . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow: auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
} catch (Throwable $e) {
    echo "<hr>";
    echo "<strong style='color: red;'>❌ Fatal Error: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
    echo "<strong>File:</strong> " . htmlspecialchars($e->getFile()) . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow: auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
