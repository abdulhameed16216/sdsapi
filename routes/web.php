<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\AuthController as WebAuthController;
use App\Http\Controllers\Web\DashboardController as WebDashboardController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Public web routes
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/login', [WebAuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [WebAuthController::class, 'login']);
Route::get('/register', [WebAuthController::class, 'showRegistrationForm'])->name('register');
Route::post('/register', [WebAuthController::class, 'register']);

// Protected web routes
Route::middleware(['auth'])->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    
    // Dashboard routes
    Route::get('/dashboard', [WebDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/analytics', [WebDashboardController::class, 'analytics'])->name('dashboard.analytics');
    Route::get('/dashboard/users', [WebDashboardController::class, 'users'])->name('dashboard.users');
    Route::get('/dashboard/settings', [WebDashboardController::class, 'settings'])->name('dashboard.settings');
    
    // Profile routes
    Route::get('/profile', [WebAuthController::class, 'showProfile'])->name('profile');
    Route::put('/profile', [WebAuthController::class, 'updateProfile']);
});
