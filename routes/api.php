<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductThresholdController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\InternalStockInController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\StockAvailabilityController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\StockReportController;
use App\Http\Controllers\Api\StockSyncController;
use App\Http\Controllers\Api\DeliveryToCustomerController;
use App\Http\Controllers\Api\DeliveryManagementController;
use App\Http\Controllers\Api\StockInController;
use App\Http\Controllers\Api\MachineAssignmentController;
use App\Http\Controllers\Api\EmployeeCustomerMachineAssignmentController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\MachineReadingController;
use App\Http\Controllers\Api\FloorStockTransferController;
use App\Http\Controllers\Api\CustomerReturnController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CustomerGroupController;
use App\Http\Controllers\Api\CustomerLocationFloorStockController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Cleanup route (can be called by cron job)
Route::post('/cleanup-expired-tokens', [AuthController::class, 'cleanupExpiredTokens']);

// Protected routes
Route::middleware('auth:api')->group(function () {
    // Authentication routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);
    
// Profile management routes
Route::get('/profile', [ProfileController::class, 'show']);
Route::put('/profile/update', [ProfileController::class, 'update']);
Route::put('/profile/reset-password', [ProfileController::class, 'resetPassword']);
Route::delete('/profile/remove-photo', [ProfileController::class, 'removeProfilePhoto']);

// Dashboard analytics routes
Route::get('/dashboard/analytics', [DashboardAnalyticsController::class, 'getAnalytics']);
Route::get('/dashboard/counts', [DashboardAnalyticsController::class, 'getCounts']);
Route::get('/dashboard/attendance', [DashboardAnalyticsController::class, 'getAttendance']);
Route::get('/dashboard/recent-deliveries', [DashboardAnalyticsController::class, 'getRecentDeliveries']);
Route::get('/dashboard/stock-alerts', [DashboardAnalyticsController::class, 'getStockAlerts']);

// Assignment management routes
Route::get('/assignments', [AssignmentController::class, 'index']);
Route::post('/assignments/vendor-machine', [AssignmentController::class, 'assignVendorToMachine']);
Route::post('/assignments/employee-vendor', [AssignmentController::class, 'assignEmployeeToVendor']);
Route::delete('/assignments/vendor-machine/{id}', [AssignmentController::class, 'removeVendorFromMachine']);
Route::delete('/assignments/employee-vendor/{id}', [AssignmentController::class, 'removeEmployeeFromVendor']);
Route::get('/assignments/available-vendors', [AssignmentController::class, 'getAvailableVendors']);
Route::get('/assignments/available-machines', [AssignmentController::class, 'getAvailableMachines']);
Route::get('/assignments/available-employees', [AssignmentController::class, 'getAvailableEmployees']);

// Machine Assignment Routes
Route::prefix('machine-assignments')->group(function () {
    Route::get('/', [MachineAssignmentController::class, 'index']);
    Route::post('/', [MachineAssignmentController::class, 'store']);
    Route::get('/{id}', [MachineAssignmentController::class, 'show']);
    Route::put('/{id}', [MachineAssignmentController::class, 'update']);
    Route::delete('/{id}', [MachineAssignmentController::class, 'destroy']);
    Route::get('/stats/overview', [MachineAssignmentController::class, 'stats']);
    Route::get('/test-data', [MachineAssignmentController::class, 'testData']);
    Route::get('/customer/{customerId}', [MachineAssignmentController::class, 'getCustomerAssignments']);
    Route::get('/machine/{machineId}', [MachineAssignmentController::class, 'getMachineAssignments']);
});

// Vendor Machine Assignment Routes (using same controller)
Route::prefix('vendor-machine-assignments')->group(function () {
    Route::get('/', [MachineAssignmentController::class, 'index']);
    Route::post('/', [MachineAssignmentController::class, 'store']);
    Route::get('/{id}', [MachineAssignmentController::class, 'show']);
    Route::put('/{id}', [MachineAssignmentController::class, 'update']);
    Route::delete('/{id}', [MachineAssignmentController::class, 'destroy']);
    Route::get('/vendor/{vendorId}', [MachineAssignmentController::class, 'getCustomerAssignments']);
    Route::get('/machine/{machineId}', [MachineAssignmentController::class, 'getMachineAssignments']);
});

// Employee Customer Machine Assignment Routes
Route::prefix('employee-customer-machine-assignments')->group(function () {
    Route::get('/', [EmployeeCustomerMachineAssignmentController::class, 'index']);
    Route::post('/', [EmployeeCustomerMachineAssignmentController::class, 'store']);
    Route::get('/{id}', [EmployeeCustomerMachineAssignmentController::class, 'show']);
    Route::put('/{id}', [EmployeeCustomerMachineAssignmentController::class, 'update']);
    Route::delete('/{id}', [EmployeeCustomerMachineAssignmentController::class, 'destroy']);
});
    
    // User management routes
    Route::apiResource('users', UserController::class);
    Route::get('/users/stats', [UserController::class, 'stats']);
    
    // Role management routes
    Route::apiResource('roles', RoleController::class);
    Route::get('/roles/list', [RoleController::class, 'list']);
    Route::get('/roles/{role}/privileges', [RoleController::class, 'getRolePrivileges']);
    Route::post('/roles/{id}/restore', [RoleController::class, 'restore']);
    Route::delete('/roles/{id}/force-delete', [RoleController::class, 'forceDelete']);
    
    // Vendor management routes
    Route::apiResource('vendors', VendorController::class);
    Route::post('/vendors/{id}/update', [VendorController::class, 'updateViaPost']);
    Route::get('/vendors/export/excel', [VendorController::class, 'exportExcel']); // Export to Excel
    Route::get('/vendors/export/pdf', [VendorController::class, 'exportPdf']); // Export to PDF
    Route::get('/vendors/{vendor}/stats', [VendorController::class, 'stats']);
    Route::get('/vendors/{vendor}/products', [VendorController::class, 'getAssignedProducts']);
    Route::post('/vendors/{vendor}/products/assign', [VendorController::class, 'assignProducts']);
    Route::delete('/vendors/{vendor}/products/{product}', [VendorController::class, 'removeProduct']);
    
    // Product management routes (specific routes must come before apiResource)
    Route::get('/products/export/excel', [ProductController::class, 'exportExcel']); // Export to Excel
    Route::get('/products/export/pdf', [ProductController::class, 'exportPdf']); // Export to PDF
    Route::apiResource('products', ProductController::class)->except(['update']);
    Route::post('/products/{product}/update', [ProductController::class, 'update']);
    Route::get('/products/{product}/stats', [ProductController::class, 'stats']);
    Route::get('/products/{product}/image', [ProductController::class, 'getProductImageUrl']);
    Route::get('/products/units/list', [ProductController::class, 'units']);
    
    // Product Threshold routes
    Route::get('/product-thresholds', [ProductThresholdController::class, 'index']);
    Route::post('/product-thresholds/{product}/update', [ProductThresholdController::class, 'update']);
    Route::post('/product-thresholds/bulk-update', [ProductThresholdController::class, 'bulkUpdate']);
    Route::get('/product-thresholds/low-stock', [ProductThresholdController::class, 'lowStock']);
    
    // Attendance management routes
    // Individual employee attendance routes (for mobile app) - MUST be before apiResource
    Route::post('/attendance/punch-in', [AttendanceController::class, 'punchIn']);
    Route::post('/attendance/punch-out', [AttendanceController::class, 'punchOut']);
    Route::post('/attendance/location-punch', [AttendanceController::class, 'locationPunch']); // Location-based punch in/out (supports multiple punches)
    Route::get('/attendance/today', [AttendanceController::class, 'getTodayAttendance']);
    Route::get('/attendance/my-attendance', [AttendanceController::class, 'getMyAttendance']);
        Route::get('/attendance/my-assigned-customers', [AttendanceController::class, 'getMyAssignedCustomers']);
        Route::get('/attendance/my-stock-scope', [AttendanceController::class, 'getMyStockScope']);
        Route::get('/attendance/my-machine-readings-scope', [AttendanceController::class, 'getMyMachineReadingsScope']);
        Route::get('/attendance/my-assigned-machines', [AttendanceController::class, 'getMyAssignedMachines']);
    Route::get('/attendance/employees/list', [AttendanceController::class, 'getEmployees']);
    Route::get('/attendance/customers/list', [AttendanceController::class, 'getCustomers']);
    Route::get('/attendance/report', [AttendanceController::class, 'getReport']); // Aggregated attendance report
    Route::get('/attendance/report/export/excel', [AttendanceController::class, 'exportExcel']); // Export to Excel
    Route::get('/attendance/report/export/pdf', [AttendanceController::class, 'exportPdf']); // Export to PDF
    Route::get('/attendance/employee-day-transactions', [AttendanceController::class, 'getEmployeeDayTransactions']); // Detailed transactions for employee and date

    // Attendance resource routes (must be after specific routes)
    Route::apiResource('attendance', AttendanceController::class);

    // File Upload routes
    Route::apiResource('file-uploads', FileUploadController::class);
    Route::get('/file-uploads/{fileUpload}/download', [FileUploadController::class, 'download']);
    Route::get('/file-uploads/categories/list', [FileUploadController::class, 'categories']);
    Route::get('/file-uploads/status/list', [FileUploadController::class, 'statusOptions']);

    // Delivery routes
    Route::apiResource('deliveries', DeliveryController::class);
    Route::get('/deliveries/customers/list', [DeliveryController::class, 'getCustomers']);
    Route::get('/deliveries/products/list', [DeliveryController::class, 'getProducts']);
    
    // Stock Availability routes
    Route::get('/stock-availability/customers/list', [StockAvailabilityController::class, 'getCustomers']);
    Route::get('/stock-availability/products/list', [StockAvailabilityController::class, 'getProducts']);
    Route::get('/stock-availability/data', [StockAvailabilityController::class, 'getAvailabilityData']);
    Route::get('/stock-availability/data-simple', [StockAvailabilityController::class, 'getAvailabilityDataSimple']);
    Route::get('/stock-availability/test', [StockAvailabilityController::class, 'testStockData']);
    Route::get('/stock-availability/test-simple', [StockAvailabilityController::class, 'testSimple']);
    Route::get('/stock-availability/test-models', [StockAvailabilityController::class, 'testModels']);
    Route::get('/stock-availability/popup-products', [StockAvailabilityController::class, 'getProductsForAvailabilityPopup']);
    Route::get('/stock-availability/current-stock', [StockAvailabilityController::class, 'getCurrentStock']);
    Route::get('/stock-availability/previous-day-closing', [StockAvailabilityController::class, 'getPreviousDayClosing']);
    Route::post('/stock-availability/recalculate', [StockAvailabilityController::class, 'recalculate']);
    Route::get('/stock-availability/floor-used-today', [StockAvailabilityController::class, 'floorUsedToday']);
    Route::put('/stock-availability/floor-used-today/{id}', [StockAvailabilityController::class, 'updateFloorUsedEntry']);
    Route::delete('/stock-availability/floor-used-today/{id}', [StockAvailabilityController::class, 'deleteFloorUsedEntry']);
    Route::get('/stock-availability/export/excel', [StockAvailabilityController::class, 'exportExcel']);
    Route::get('/stock-availability/export/pdf', [StockAvailabilityController::class, 'exportPdf']);
    
    // Mobile app specific stock availability routes (must be before apiResource)
    Route::get('/stock-availability/mobile/data', [StockAvailabilityController::class, 'getMobileAvailabilityData']);
    Route::post('/stock-availability/mobile/save', [StockAvailabilityController::class, 'storeMobileAvailability']);
    Route::get('/stock-availability/saved', [StockAvailabilityController::class, 'getSavedStockAvailability']);
    
    Route::apiResource('stock-availability', StockAvailabilityController::class);
    
    // Customer Returns routes
    Route::get('/customer-returns/pending-count', [CustomerReturnController::class, 'getPendingCount']);
    Route::get('/customer-returns/return-balance', [CustomerReturnController::class, 'getReturnBalance']);
    Route::get('/customer-returns/customers/list', [CustomerReturnController::class, 'getCustomers']);
    Route::get('/customer-returns/products/list', [CustomerReturnController::class, 'getProducts']);
    Route::post('/customer-returns/{id}/approve', [CustomerReturnController::class, 'approve']);
    Route::post('/customer-returns/{id}/reject', [CustomerReturnController::class, 'reject']);
    Route::post('/customer-returns/{id}/move-to-internal', [CustomerReturnController::class, 'moveToInternal']);
    Route::post('/customer-returns/{id}/return-to-vendor', [CustomerReturnController::class, 'returnToVendor']);
    Route::post('/customer-returns/{id}/dispose', [CustomerReturnController::class, 'dispose']);
    Route::apiResource('customer-returns', CustomerReturnController::class);
    
    // Stock Transfer routes - specific routes first to avoid conflicts with apiResource
    Route::get('/stock-transfers/customers/list', [StockTransferController::class, 'getCustomers']);
    Route::get('/stock-transfers/products/list', [StockTransferController::class, 'getProducts']);
    Route::get('/stock-transfers/stock-availability', [StockTransferController::class, 'getStockAvailability']);
    Route::get('/stock-transfers/transfer-available-stock', [StockTransferController::class, 'getTransferAvailableStock']);
    Route::get('/stock-transfers/{id}/products', [StockTransferController::class, 'getTransferProducts']);
    Route::post('/stock-transfers/{id}/accept', [StockTransferController::class, 'accept']);
    Route::post('/stock-transfers/{id}/reject', [StockTransferController::class, 'reject']);
    Route::get('/stock-transfers/export/excel', [StockTransferController::class, 'exportExcel']);
    Route::get('/stock-transfers/export/pdf', [StockTransferController::class, 'exportPdf']);
    Route::apiResource('stock-transfers', StockTransferController::class);

    // Delivery to Customer routes
    Route::get('/delivery-to-customers/customers/list', [DeliveryToCustomerController::class, 'getCustomers']);
    Route::get('/delivery-to-customers/products/list', [DeliveryToCustomerController::class, 'getProducts']);
    Route::get('/delivery-to-customers/products/{productId}/available-stock', [DeliveryToCustomerController::class, 'getAvailableStockForProduct']);
    Route::get('/delivery-to-customers/{deliveryId}/products/list', [DeliveryToCustomerController::class, 'getProductsForEdit']);
    Route::post('/delivery-to-customers/{id}/accept', [DeliveryToCustomerController::class, 'acceptDelivery']);
    Route::get('/delivery-to-customers/export/excel', [DeliveryToCustomerController::class, 'exportExcel']);
    Route::get('/delivery-to-customers/export/pdf', [DeliveryToCustomerController::class, 'exportPdf']);
    Route::apiResource('delivery-to-customers', DeliveryToCustomerController::class);
    
    // Stock In routes (using StockController)
    Route::get('/stocks/stock-in', [StockController::class, 'stockIn']);
    Route::post('/stocks/stock-in', [StockController::class, 'createStockIn']);
    Route::put('/stocks/stock-in/{id}', [StockController::class, 'updateStockIn']);
    Route::delete('/stocks/stock-in/{id}', [StockController::class, 'deleteStockIn']);
    Route::get('/stocks/stock-in/customers/list', [StockController::class, 'getStockInCustomers']);
    Route::get('/stocks/stock-in/products/list', [StockController::class, 'getStockInProducts']);
    Route::get('/stocks/stock-in/debug', [StockController::class, 'debugStockIn']);
    Route::get('/stocks/stock-in/export/excel', [StockController::class, 'exportStockInExcel']);
    Route::get('/stocks/stock-in/export/pdf', [StockController::class, 'exportStockInPdf']);
    
    // Internal Stock In routes
    Route::get('/internal-stocks/stock-in', [InternalStockInController::class, 'index']);
    Route::post('/internal-stocks/stock-in', [InternalStockInController::class, 'store']);
    Route::get('/internal-stocks/stock-in/{id}', [InternalStockInController::class, 'show']);
    Route::put('/internal-stocks/stock-in/{id}', [InternalStockInController::class, 'update']);
    Route::delete('/internal-stocks/stock-in/{id}', [InternalStockInController::class, 'destroy']);
    Route::get('/internal-stocks/stock-in/vendors/list', [InternalStockInController::class, 'getVendors']);
    Route::get('/internal-stocks/stock-in/products/list', [InternalStockInController::class, 'getProducts']);
    Route::get('/internal-stocks/availability-report', [InternalStockInController::class, 'getAvailabilityReport']);
    Route::post('/internal-stocks/return-to-vendor', [InternalStockInController::class, 'returnToVendor']);
    Route::get('/internal-stocks/stock-in/export/excel', [InternalStockInController::class, 'exportExcel']);
    Route::get('/internal-stocks/stock-in/export/pdf', [InternalStockInController::class, 'exportPdf']);

    // Alternative route path for internal stock-in (stocks/internal-in)
    Route::get('/stocks/internal-in', [InternalStockInController::class, 'index']);
    Route::post('/stocks/internal-in', [InternalStockInController::class, 'store']);
    Route::get('/stocks/internal-in/{id}', [InternalStockInController::class, 'show']);
    Route::put('/stocks/internal-in/{id}', [InternalStockInController::class, 'update']);
    Route::delete('/stocks/internal-in/{id}', [InternalStockInController::class, 'destroy']);
    Route::get('/stocks/internal-in/vendors/list', [InternalStockInController::class, 'getVendors']);
    Route::get('/stocks/internal-in/products/list', [InternalStockInController::class, 'getProducts']);
    Route::get('/stocks/internal-in/availability-report', [InternalStockInController::class, 'getAvailabilityReport']);
    Route::get('/stocks/internal-in/export/excel', [InternalStockInController::class, 'exportExcel']);
    Route::get('/stocks/internal-in/export/pdf', [InternalStockInController::class, 'exportPdf']);
    
    // New Delivery Management routes (with return functionality)
    Route::get('/delivery-management/customers/list', [DeliveryManagementController::class, 'getCustomers']);
    Route::get('/delivery-management/products/list', [DeliveryManagementController::class, 'getProducts']);
    Route::get('/delivery-management/returns', [DeliveryManagementController::class, 'getReturns']);
    Route::get('/delivery-management/statistics', [DeliveryManagementController::class, 'getStatistics']);
    Route::post('/delivery-management/{deliveryProduct}/update-return', [DeliveryManagementController::class, 'updateReturn']);
    Route::apiResource('delivery-management', DeliveryManagementController::class);
    
    // Stock Report routes
    Route::get('/stock-reports', [StockReportController::class, 'index']);
    Route::get('/stock-reports/all-stock-products', [StockReportController::class, 'getAllStockProducts']);
    Route::get('/stock-reports/delivery-products', [StockReportController::class, 'getDeliveryProducts']);
    Route::get('/stock-reports/stock-availability', [StockReportController::class, 'getStockAvailabilityReport']);
    Route::get('/stock-reports/transfer-products', [StockReportController::class, 'getTransferProducts']);
    Route::get('/stock-reports/daily', [StockReportController::class, 'getDailyReports']);
    Route::get('/stock-reports/summary', [StockReportController::class, 'getReportSummary']);
    Route::post('/stock-reports/update', [StockReportController::class, 'updateReport']);
    Route::post('/stock-reports/recalculate', [StockReportController::class, 'recalculateReports']);
    Route::get('/stock-reports/customers/list', [StockReportController::class, 'getCustomers']);
    Route::get('/stock-reports/export/excel', [StockReportController::class, 'exportExcel']); // Export to Excel
    Route::get('/stock-reports/export/pdf', [StockReportController::class, 'exportPdf']); // Export to PDF
    
    // Stock Sync routes
    Route::get('/stock-sync/calculation', [StockSyncController::class, 'getStockMovementCalculation']);
    Route::post('/stock-sync/sync-all-existing', [StockSyncController::class, 'syncAllExistingStocks']);
    Route::get('/stock-sync/test-status', [StockSyncController::class, 'testSyncStatus']);
    Route::get('/stock-reports/products/list', [StockReportController::class, 'getProducts']);
    
    // Stock management routes
    Route::apiResource('stocks', StockController::class);
    Route::post('/stocks/usage', [StockController::class, 'recordUsage']);
    Route::post('/stocks/transfer', [StockController::class, 'transfer']);
    Route::get('/stocks/alerts', [StockController::class, 'alerts']);
    Route::get('/stocks/transactions', [StockController::class, 'transactions']);
    
    // Attendance management routes
    Route::apiResource('attendances', AttendanceController::class);
    Route::get('/attendances/today', [AttendanceController::class, 'today']);
    Route::get('/attendances/summary', [AttendanceController::class, 'summary']);
    Route::get('/attendances/vendors/available', [AttendanceController::class, 'availableVendors']);
    
    // Machine management routes
    // Export routes must come before apiResource to avoid route conflicts
    Route::get('/machines/export/excel', [MachineController::class, 'exportExcel']); // Export to Excel
    Route::get('/machines/export/pdf', [MachineController::class, 'exportPdf']); // Export to PDF
    Route::get('/machines/unassigned-for-assignment', [MachineController::class, 'unassignedForCustomerAssignment']);
    Route::apiResource('machines', MachineController::class)->except(['update']);
    Route::post('/machines/{machine}/update', [MachineController::class, 'update']);
    Route::get('/machines/{machine}/image', [MachineController::class, 'getMachineImageUrl']);
    
    // Machine Reading routes (specific routes must come before apiResource)
    Route::get('/machine-readings/existing', [MachineReadingController::class, 'getExistingReading']);
    Route::apiResource('machine-readings', MachineReadingController::class)->only(['index', 'store', 'show']);
    
    // Employee management routes
    Route::apiResource('employees', EmployeeController::class);
    Route::post('/employees/{id}/update', [EmployeeController::class, 'updateViaPost']);
    Route::get('/employees/stats', [EmployeeController::class, 'stats']);
    Route::get('/employees/next-code', [EmployeeController::class, 'getNextEmployeeCode']);
    Route::post('/employees/{id}/restore', [EmployeeController::class, 'restore']);
    Route::delete('/employees/{id}/force-delete', [EmployeeController::class, 'forceDelete']);
    Route::post('/employees/fix-duplicate-codes', [EmployeeController::class, 'fixDuplicateEmployeeCodes']);
    Route::get('/employees/export/excel', [EmployeeController::class, 'exportExcel']); // Export to Excel
    Route::get('/employees/export/pdf', [EmployeeController::class, 'exportPdf']); // Export to PDF
    
    // Customer management routes
    Route::apiResource('customers', CustomerController::class)->except(['update']);
    Route::post('/customers/{customer}/update', [CustomerController::class, 'update']);
    Route::get('/customers/export/excel', [CustomerController::class, 'exportExcel']); // Export to Excel
    Route::get('/customers/export/pdf', [CustomerController::class, 'exportPdf']); // Export to PDF
    Route::get('/customers/stats', [CustomerController::class, 'stats']);
    Route::get('/customers/{customer}/logo', [CustomerController::class, 'getLogoUrl']);
    Route::post('/customers/{id}/restore', [CustomerController::class, 'restore']);
    Route::delete('/customers/{id}/force-delete', [CustomerController::class, 'forceDelete']);
    Route::get('/customers/{customer}/products', [CustomerController::class, 'getAssignedProducts']);
    Route::post('/customers/{customer}/products/assign', [CustomerController::class, 'assignProducts']);
    Route::delete('/customers/{customer}/products/{product}', [CustomerController::class, 'removeProduct']);
    Route::get('/customers/{customer}/stock-by-location-floor', [CustomerLocationFloorStockController::class, 'show']);
    Route::post('/customers/{customer}/stock-by-location-floor/move', [CustomerLocationFloorStockController::class, 'move']);

    // Floor stock transfers (branch pool <-> floor, floor <-> floor)
    Route::get('/floor-stock-transfers', [FloorStockTransferController::class, 'index']);
    Route::post('/floor-stock-transfers', [FloorStockTransferController::class, 'store']);
    Route::put('/floor-stock-transfers/{id}', [FloorStockTransferController::class, 'update']);
    Route::delete('/floor-stock-transfers/{id}', [FloorStockTransferController::class, 'destroy']);
    
    // Permission management routes
    Route::get('/permissions/config', [PermissionController::class, 'getConfig']);
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/module/{module}', [PermissionController::class, 'getByModule']);
    
    // Document management routes
    Route::apiResource('documents', DocumentController::class);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::get('/documents/alerts', [DocumentController::class, 'alerts']);
    Route::get('/documents/types/list', [DocumentController::class, 'types']);
    
    // Dashboard routes
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/recent-activities', [DashboardController::class, 'getRecentActivities']);
    
    // Stock Alert routes
    Route::get('/stock-alerts', [StockAlertController::class, 'index']);
    Route::get('/stock-alerts/customer', [StockAlertController::class, 'getCustomerAlerts']);
    Route::get('/stock-alerts/internal', [StockAlertController::class, 'getInternalAlerts']);
    Route::get('/stock-alerts/check-customer/{customerId}', [StockAlertController::class, 'checkCustomerAlerts']);
    Route::post('/stock-alerts/send-email', [StockAlertController::class, 'sendEmail']);

    // Backup / Deleted records (soft-deleted) – list, revert, force delete
    Route::get('/backup/modules', [BackupController::class, 'modules']);
    Route::get('/backup/{module}', [BackupController::class, 'index']);
    Route::post('/backup/{module}/{id}/revert', [BackupController::class, 'revert']);
    Route::delete('/backup/{module}/{id}/force-delete', [BackupController::class, 'forceDelete']);

    // Customer groups (master: groups → locations/customers → floors)
    Route::get('/customer-groups', [CustomerGroupController::class, 'index']);
    Route::get('/customer-groups/{id}', [CustomerGroupController::class, 'show']);
    Route::post('/customer-groups', [CustomerGroupController::class, 'store']);
    Route::put('/customer-groups/{id}', [CustomerGroupController::class, 'update']);
    Route::delete('/customer-groups/{id}', [CustomerGroupController::class, 'destroy']);
    // Floors / sections under a location (customer branch)
    Route::post('/customer-groups/locations/{locationId}/floors', [CustomerGroupController::class, 'storeFloor']);
    Route::put('/customer-groups/floors/{floorId}', [CustomerGroupController::class, 'updateFloor']);
    Route::delete('/customer-groups/floors/{floorId}', [CustomerGroupController::class, 'destroyFloor']);
    Route::get('/customer-groups/floors/{floorId}/employees', [CustomerGroupController::class, 'floorEmployees']);
    Route::post('/customer-groups/floors/{floorId}/employees', [CustomerGroupController::class, 'storeFloorEmployee']);
    Route::delete('/customer-groups/floors/{floorId}/employees/{assignmentId}', [CustomerGroupController::class, 'destroyFloorEmployee']);
});

// Notifications routes
Route::prefix('notifications')->middleware('auth:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('/unread-count', [App\Http\Controllers\Api\NotificationController::class, 'getUnreadCount']);
    Route::get('/{id}', [App\Http\Controllers\Api\NotificationController::class, 'show']);
    Route::put('/{id}/read', [App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::put('/mark-all-read', [App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [App\Http\Controllers\Api\NotificationController::class, 'destroy']);
    Route::delete('/clear-all', [App\Http\Controllers\Api\NotificationController::class, 'clearAll']);
});

// Health check route
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'version' => '1.0.0'
    ]);
});
