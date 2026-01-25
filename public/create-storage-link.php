<?php
/**
 * Storage Symlink Creation Script
 * 
 * This script creates a symbolic link from public/storage to storage/app/public
 * This allows access to uploaded files via the web.
 * 
 * Run this script via browser or CLI:
 * php create-storage-link.php
 */

$target = realpath(__DIR__ . '/../storage/app/public');
$link = __DIR__ . '/storage';

// Get domain name from server or use default
$domain = isset($_SERVER['HTTP_HOST']) 
    ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
    : 'https://sdsapi.veeyaainnovatives.com';

echo "Domain: $domain<br>";
echo "Target: $target<br>";
echo "Link: $link<br>";

// Ensure storage/app/public directory exists
if (!$target) {
    $publicDir = __DIR__ . '/../storage/app/public';
    if (!is_dir($publicDir)) {
        if (mkdir($publicDir, 0755, true)) {
            echo "Created storage/app/public directory: $publicDir<br>";
            $target = realpath($publicDir);
        } else {
            echo "Error: Could not create storage/app/public directory.<br>";
            exit(1);
        }
    } else {
        $target = realpath($publicDir);
    }
}

// Ensure uploads folder exists
$uploadsFolder = $target . '/uploads';
if (!is_dir($uploadsFolder)) {
    if (mkdir($uploadsFolder, 0755, true)) {
        echo "Created uploads folder: $uploadsFolder<br>";
    } else {
        echo "Warning: Could not create uploads folder.<br>";
    }
}

if (file_exists($link)) {
    echo "Deleting old storage folder/link...<br>";
    
    if (is_dir($link) && !is_link($link)) {
        // Remove normal directory (only works if empty)
        if (rmdir($link)) {
            echo "Old directory removed successfully.<br>";
        } else {
            echo "Warning: Could not remove old directory (may not be empty).<br>";
            echo "Please remove it manually: rm -rf $link<br>";
        }
    } else {
        // Remove symlink or file
        if (unlink($link)) {
            echo "Old link/file removed successfully.<br>";
        } else {
            echo "Warning: Could not remove old link/file.<br>";
            echo "Please remove it manually: rm $link<br>";
        }
    }
} else {
    echo "No existing link found. Creating new symlink...<br>";
}

if (!$target) {
    echo "Error: Target directory does not exist: " . __DIR__ . '/../storage/app/public<br>';
    exit(1);
}

echo "Creating symlink from: $link<br>";
echo "To target: $target<br>";

if (symlink($target, $link)) {
    echo "<br><strong>SUCCESS: Symlink created successfully!</strong><br>";
    echo "<br>Your files are now accessible at:<br>";
    echo "<strong>$domain/storage/uploads/...</strong><br>";
    echo "<br>Example image URL:<br>";
    echo "<code>$domain/storage/uploads/gallery/folder/image.jpg</code><br>";
} else {
    echo "<br><strong>ERROR: Failed to create symlink.</strong><br>";
    echo "Possible reasons:<br>";
    echo "1. Insufficient permissions (try: chmod 755 public/)<br>";
    echo "2. Symlinks not supported on this system<br>";
    echo "3. Path already exists and couldn't be removed<br>";
    echo "<br>Alternative: Use Laravel's artisan command:<br>";
    echo "php artisan storage:link<br>";
}

