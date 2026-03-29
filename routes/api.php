<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\CareerController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\AssignProjectController;
use App\Http\Controllers\Api\PdfDownloadController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\PartnerProgramController;

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

// Public routes (no authentication required)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken']);
Route::post('/reset-password-with-token', [AuthController::class, 'resetPasswordWithToken']);
Route::post('/refresh', [AuthController::class, 'refresh']); // Refresh token doesn't require auth

// Public blog routes (for website viewing - no authentication required)
Route::get('/public/blogs', [BlogController::class, 'publicIndex']);
Route::get('/public/blogs/{slug}', [BlogController::class, 'publicShow']);

// Public service routes (for website viewing - no authentication required)
Route::get('/public/services/menu', [ServiceController::class, 'publicMenu']);
Route::get('/public/services/all-with-children', [ServiceController::class, 'publicGetAllWithChildren']);
Route::get('/public/services/{parentId}/sub-services', [ServiceController::class, 'publicGetSubServices']);
Route::get('/public/services', [ServiceController::class, 'publicIndex']);
Route::get('/public/services/{slug}', [ServiceController::class, 'publicShow']);

// Public subscription routes (for website viewing - no authentication required)
Route::get('/public/subscriptions', [SubscriptionController::class, 'publicIndex']);
Route::get('/public/subscriptions/{id}', [SubscriptionController::class, 'publicShow']);

// Public FAQ routes (for website viewing - no authentication required)
Route::get('/public/faqs', [FaqController::class, 'publicIndex']);
Route::get('/public/faqs/{id}', [FaqController::class, 'publicShow']);

// Public partner program routes (for website viewing - no authentication required)
Route::get('/public/partner-programs', [PartnerProgramController::class, 'publicIndex']);
Route::get('/public/partner-programs/{id}', [PartnerProgramController::class, 'publicShow']);

// Public PDF download routes (for website viewing - no authentication required)
Route::get('/public/pdf-downloads', [PdfDownloadController::class, 'publicIndex']);
Route::get('/public/pdf-downloads/{id}', [PdfDownloadController::class, 'publicShow']);

// Public portfolio gallery routes (for website viewing - no authentication required)
Route::get('/public/portfolio', [GalleryController::class, 'publicPortfolio']);
Route::get('/public/portfolio/{id}', [GalleryController::class, 'publicPortfolioShow']);

// Public quote request route (no authentication required)
// Can be used for both quote and contactus by setting request_type parameter
Route::post('/public/quote', [QuoteController::class, 'store']);

// Public contact us route (no authentication required)
// Uses same controller as quote, automatically sets request_type to 'contactus'
Route::post('/public/contact', [QuoteController::class, 'store']);

// Public subscription request route (no authentication required)
// Automatically sets request_type to 'subscription'
Route::post('/public/subscription', [QuoteController::class, 'store']);

// Public partner program request route (no authentication required)
// Automatically sets request_type to 'partner'
Route::post('/public/partner', [QuoteController::class, 'store']);

// Public career application route (no authentication required)
Route::post('/public/career', [CareerController::class, 'store']);

// Public customer authentication routes (no authentication required)
Route::post('/public/customer/login', [CustomerAuthController::class, 'login']);
Route::post('/public/customer/refresh', [CustomerAuthController::class, 'refresh']);
Route::post('/public/customer/reset-password', [CustomerAuthController::class, 'resetPassword']);
Route::post('/public/customer/verify-reset-token', [CustomerAuthController::class, 'verifyResetToken']);
Route::post('/public/customer/reset-password-with-token', [CustomerAuthController::class, 'resetPasswordWithToken']);

// Protected routes (require JWT authentication)
Route::middleware('auth:api')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // Dashboard statistics
    Route::get('/dashboard/statistics', [DashboardController::class, 'statistics']);
    Route::get('/dashboard/time-series', [DashboardController::class, 'timeSeries']);
    
    // User CRUD
    Route::apiResource('users', UserController::class);
    Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    
    // Blog CRUD
    Route::apiResource('blogs', BlogController::class);
    Route::patch('/blogs/{id}/status', [BlogController::class, 'updateStatus']);
    
    // Service CRUD
    Route::apiResource('services', ServiceController::class);
    Route::patch('/services/{id}/status', [ServiceController::class, 'updateStatus']);
    Route::post('/services/{id}/clone', [ServiceController::class, 'clone']);
    Route::get('/services/main/list', [ServiceController::class, 'getMainServices']);
    Route::get('/services/{parentId}/sub-services', [ServiceController::class, 'getSubServices']);
    
    // FAQ CRUD
    Route::apiResource('faqs', FaqController::class);
    
    // Partner Program CRUD
    Route::apiResource('partner-programs', PartnerProgramController::class);
    
    // Subscription CRUD
    Route::apiResource('subscriptions', SubscriptionController::class);
    Route::patch('/subscriptions/{id}/status', [SubscriptionController::class, 'updateStatus']);
    
    // Gallery CRUD
    Route::apiResource('gallery', GalleryController::class);
    
    // Quote CRUD (for viewing quotes in admin dashboard)
    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::post('/quotes', [QuoteController::class, 'adminStore']); // Admin create (no emails)
    Route::get('/quotes/{id}', [QuoteController::class, 'show']);
    Route::put('/quotes/{id}', [QuoteController::class, 'update']); // Admin update
    Route::delete('/quotes/{id}', [QuoteController::class, 'destroy']);
    
    // Customer CRUD (admin only - uses api guard)
    Route::apiResource('customers', CustomerController::class);
    
    // Project CRUD
    Route::apiResource('projects', ProjectController::class);
    Route::post('/projects/{id}/steps', [ProjectController::class, 'addStep']);
    Route::put('/projects/{projectId}/steps/{stepId}', [ProjectController::class, 'updateStep']);
    Route::delete('/projects/{projectId}/steps/{stepId}', [ProjectController::class, 'deleteStep']);
    
    // Assign Projects (New Controller - POST method for both create and update)
    Route::post('/assign-projects', [AssignProjectController::class, 'store']);
    Route::post('/assign-projects/{id}', [AssignProjectController::class, 'update']);
    
    // Project document viewing (authenticated access)
    Route::get('/projects/documents/{fileName}', [ProjectController::class, 'viewDocument']);
    
    // PDF Downloads CRUD
    Route::apiResource('pdf-downloads', PdfDownloadController::class);
});

// Customer protected routes (require customer JWT authentication)
Route::middleware('auth:customer-api')->group(function () {
    Route::get('/customer/me', [CustomerAuthController::class, 'me']);
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);
    Route::post('/customer/change-password', [CustomerAuthController::class, 'changePassword']);
    
    // Customer projects (assigned projects)
    Route::get('/customer/projects', [ProjectController::class, 'customerProjects']);
    Route::get('/customer/projects/{id}', [ProjectController::class, 'customerProjectShow']);
    
    // Project document viewing (authenticated access for customers)
    Route::get('/projects/documents/{fileName}', [ProjectController::class, 'viewDocument']);
});

