# Script to find unused images in assets folder
$assetsPath = "ebms/src/assets/images"
$codePath = "ebms/src"

# Get all image files
$imageFiles = Get-ChildItem -Path $assetsPath -Recurse -Include *.png,*.jpg,*.jpeg,*.gif,*.svg,*.webp | ForEach-Object {
    $relativePath = $_.FullName.Replace((Resolve-Path $assetsPath).Path + '\', 'assets/images/').Replace('\', '/')
    [PSCustomObject]@{
        FullPath = $_.FullName
        RelativePath = $relativePath
        FileName = $_.Name
        Directory = $_.DirectoryName
    }
}

Write-Host "Total images found: $($imageFiles.Count)" -ForegroundColor Cyan
Write-Host "`nChecking for unused images...`n" -ForegroundColor Yellow

$unusedImages = @()
$usedImages = @()

foreach ($image in $imageFiles) {
    $imageName = $image.FileName
    $relativePath = $image.RelativePath
    $relativePathEscaped = [regex]::Escape($relativePath)
    
    # Search in all code files
    $found = $false
    
    # Search for full path
    $results = Select-String -Path "$codePath\**\*" -Pattern $relativePathEscaped -ErrorAction SilentlyContinue
    if ($results) {
        $found = $true
    }
    
    # Search for just filename (might be referenced differently)
    if (-not $found) {
        $results = Select-String -Path "$codePath\**\*" -Pattern [regex]::Escape($imageName) -ErrorAction SilentlyContinue
        if ($results) {
            $found = $true
        }
    }
    
    if ($found) {
        $usedImages += $image
    } else {
        $unusedImages += $image
    }
}

Write-Host "=== UNUSED IMAGES ===" -ForegroundColor Red
Write-Host "Total unused: $($unusedImages.Count)`n" -ForegroundColor Red

if ($unusedImages.Count -gt 0) {
    foreach ($img in $unusedImages) {
        Write-Host $img.RelativePath -ForegroundColor Yellow
    }
} else {
    Write-Host "No unused images found!" -ForegroundColor Green
}

Write-Host "`n=== SUMMARY ===" -ForegroundColor Cyan
Write-Host "Total images: $($imageFiles.Count)" -ForegroundColor White
Write-Host "Used images: $($usedImages.Count)" -ForegroundColor Green
Write-Host "Unused images: $($unusedImages.Count)" -ForegroundColor Red

# Export to file
$unusedImages | Select-Object RelativePath, FileName, Directory | Export-Csv -Path "unused_images.csv" -NoTypeInformation
Write-Host "`nResults exported to: unused_images.csv" -ForegroundColor Cyan

