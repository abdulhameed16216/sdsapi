<?php
/**
 * Clear Laravel Config Cache
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to your project root on Hostinger
 * 2. Visit: https://eb-develop-api.veeyaainnovatives.com/clear_config_cache.php
 * 3. Check the output - should say "Cache cleared successfully!"
 * 4. DELETE this file immediately after use (security)
 */

// Check if running from public folder or project root
$basePath = file_exists(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/..' : __DIR__;

require $basePath.'/vendor/autoload.php';

$app = require_once $basePath.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

try {
    // Clear all caches
    Artisan::call('config:clear');
    echo "✓ Config cache cleared<br>";
    
    Artisan::call('cache:clear');
    echo "✓ Application cache cleared<br>";
    
    Artisan::call('route:clear');
    echo "✓ Route cache cleared<br>";
    
    Artisan::call('view:clear');
    echo "✓ View cache cleared<br>";
    
    // Rebuild config cache
    Artisan::call('config:cache');
    echo "✓ Config cache rebuilt<br>";
    
    echo "<br><strong style='color: green;'>✅ All caches cleared successfully!</strong><br>";
    echo "<br><strong style='color: red;'>⚠️ IMPORTANT: Delete this file now for security!</strong>";
    
} catch (Exception $e) {
    echo "<strong style='color: red;'>❌ Error: " . $e->getMessage() . "</strong>";
}

