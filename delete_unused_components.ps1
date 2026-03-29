# Script to delete unused components
# Based on unused_components_list.txt

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "DELETING UNUSED COMPONENTS" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$foldersToDelete = @(
    "ebms/src/app/demo/ui-elements",
    "ebms/src/app/demo/pages/core-chart",
    "ebms/src/app/demo/pages/form-elements",
    "ebms/src/app/demo/pages/layout",
    "ebms/src/app/demo/pages/sample-page",
    "ebms/src/app/demo/pages/tables"
)

$deletedCount = 0
$notFoundCount = 0

foreach ($folder in $foldersToDelete) {
    if (Test-Path $folder) {
        Write-Host "Deleting: $folder" -ForegroundColor Yellow
        try {
            Remove-Item -Path $folder -Recurse -Force
            Write-Host "  ✓ Deleted successfully" -ForegroundColor Green
            $deletedCount++
        } catch {
            Write-Host "  ✗ Error deleting: $_" -ForegroundColor Red
        }
    } else {
        Write-Host "Not found: $folder" -ForegroundColor Gray
        $notFoundCount++
    }
    Write-Host ""
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "SUMMARY" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Deleted: $deletedCount folders" -ForegroundColor Green
Write-Host "Not found: $notFoundCount folders" -ForegroundColor Gray
Write-Host ""
Write-Host "Done!" -ForegroundColor Green

