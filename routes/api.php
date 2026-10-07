<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

use App\Http\Controllers\Api\AuthController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/profile', [AuthController::class, 'getProfile']);
    Route::post('/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // Roles API
    Route::apiResource('roles', \App\Http\Controllers\Api\RoleController::class);

    // Modules API
    Route::apiResource('modules', \App\Http\Controllers\Api\ModuleController::class);

    // Products API
    Route::apiResource('products', \App\Http\Controllers\Api\ProductController::class);

    // Projects API
    Route::apiResource('projects', \App\Http\Controllers\Api\ProjectController::class);

    // Project Modules API
    Route::apiResource('project-modules', \App\Http\Controllers\Api\ProjectModuleController::class);

    // Billings API
    Route::apiResource('billings', \App\Http\Controllers\Api\BillingController::class);

    // Memberships API
    Route::apiResource('memberships', \App\Http\Controllers\Api\MembershipController::class);

    // Clients API
    Route::get('/clients/upcoming-renewals', [\App\Http\Controllers\Api\ClientController::class, 'upcomingRenewals']);
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class);
    
    // Setups API
    Route::apiResource('setups', \App\Http\Controllers\Api\SetupController::class);
    
    // Staff API
    Route::apiResource('staff', \App\Http\Controllers\Api\StaffController::class);

    // Dashboard & Counts API
    Route::get('/dashboard/counts', [\App\Http\Controllers\Api\DashboardController::class, 'dashboardCounts']);
    Route::get('/clients-counts', [\App\Http\Controllers\Api\DashboardController::class, 'clientsCounts']);
    Route::get('/staff-counts', [\App\Http\Controllers\Api\DashboardController::class, 'staffCounts']);
    Route::get('/memberships-counts', [\App\Http\Controllers\Api\DashboardController::class, 'membershipCounts']);

    // Renewals API
    Route::get('/renewals/sync-from-clients', [\App\Http\Controllers\Api\RenewalController::class, 'syncFromClients']);
    Route::post('/renewals/{id}/process', [\App\Http\Controllers\Api\RenewalController::class, 'process']);
    Route::get('/renewals', [\App\Http\Controllers\Api\RenewalController::class, 'index']);
    Route::get('/renewals/{id}', [\App\Http\Controllers\Api\RenewalController::class, 'show']);
    Route::post('/renewals', [\App\Http\Controllers\Api\RenewalController::class, 'store']);
    Route::put('/renewals/{id}', [\App\Http\Controllers\Api\RenewalController::class, 'update']);
    Route::delete('/renewals/{id}', [\App\Http\Controllers\Api\RenewalController::class, 'destroy']);


    // Razorpay API
    Route::post('/razorpay/create-order', [\App\Http\Controllers\Api\RazorpayController::class, 'createOrder']);
    Route::post('/razorpay/verify-payment', [\App\Http\Controllers\Api\RazorpayController::class, 'verifyPayment']);
    Route::post('/razorpay/payment-link', [\App\Http\Controllers\Api\RazorpayController::class, 'createPaymentLink']);

    // Transactions API
    Route::get('/transactions', [\App\Http\Controllers\Api\TransactionController::class, 'index']);
    Route::post('/transactions', [\App\Http\Controllers\Api\TransactionController::class, 'store']);
    Route::get('/transactions/{id}', [\App\Http\Controllers\Api\TransactionController::class, 'show']);
    Route::put('/transactions/{id}', [\App\Http\Controllers\Api\TransactionController::class, 'update']);

    // Reports API
    Route::get('/reports/summary', [\App\Http\Controllers\Api\ReportController::class, 'summary']);
    Route::get('/reports/membership-plan-performance', [\App\Http\Controllers\Api\ReportController::class, 'membershipPlanPerformance']);
    Route::get('/reports/transaction-report', [\App\Http\Controllers\Api\ReportController::class, 'transactionReport']);

    // Generic Status Update API
    Route::post('/update-status', [\App\Http\Controllers\Api\StatusController::class, 'updateStatus']);

    // Notifications API
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);

    // Settings API (Razorpay & General)
    // Settings API (Razorpay: 1 Get & 1 Save/Update)
    Route::get('/settings', [\App\Http\Controllers\Api\SettingController::class, 'getSettings']);
    Route::post('/settings', [\App\Http\Controllers\Api\SettingController::class, 'saveSettings']);
    
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Razorpay Webhook API (MUST be outside auth middleware)
Route::post('/razorpay/webhook', [\App\Http\Controllers\Api\RazorpayController::class, 'webhook']);
