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
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TermsAndConditionController;
use App\Http\Controllers\Api\InvoiceController;

Route::post('/login', [AuthController::class, 'login']);

// Client Portal API (Without Auth)
Route::post('/client/login', [ClientAuthController::class, 'login']);
Route::post('/client-login', [ClientAuthController::class, 'login']);
Route::get('/client/data/{id?}', [ClientAuthController::class, 'getClientData']);
Route::post('/client/data', [ClientAuthController::class, 'getClientData']);

// Add Client / Purchase API (Without Auth)
Route::post('/clients', [\App\Http\Controllers\Api\ClientController::class, 'store']);
Route::post('/client/register', [\App\Http\Controllers\Api\ClientController::class, 'store']);
Route::post('/client/purchase', [\App\Http\Controllers\Api\ClientController::class, 'store']);

// Products API (Without Auth)
Route::get('/products', [ProductController::class, 'getPublicProducts']);
Route::get('/products/{id}', [ProductController::class, 'getPublicProducts']);

// Projects API (Without Auth - product_id wise)
Route::get('/projects', [ProjectController::class, 'getProjectsByProduct']);
Route::get('/projects/by-product/{productId?}', [ProjectController::class, 'getProjectsByProduct']);
Route::get('/products/{productId}/projects', [ProjectController::class, 'getProjectsByProduct']);

// Memberships API (Without Auth)
Route::get('/memberships', [\App\Http\Controllers\Api\MembershipController::class, 'getPublicMemberships']);
Route::get('/memberships/{id}', [\App\Http\Controllers\Api\MembershipController::class, 'getPublicMemberships']);


// Razorpay API (Without Auth)
Route::post('/razorpay/payment-link', [\App\Http\Controllers\Api\RazorpayController::class, 'createPaymentLink']);
Route::get('/razorpay/payment-link/{linkId}', [\App\Http\Controllers\Api\RazorpayController::class, 'getPaymentLinkStatus']);
Route::post('/razorpay/create-order', [\App\Http\Controllers\Api\RazorpayController::class, 'createOrder']);
Route::post('/razorpay/order', [\App\Http\Controllers\Api\RazorpayController::class, 'createOrder']);
Route::post('/razorpay/verify-payment', [\App\Http\Controllers\Api\RazorpayController::class, 'verifyPayment']);
Route::post('/razorpay/verify-payment-link', [\App\Http\Controllers\Api\RazorpayController::class, 'verifyPayment']);
Route::post('/razorpay/verify', [\App\Http\Controllers\Api\RazorpayController::class, 'verifyPayment']);
Route::get('/razorpay/order/{orderId}', [\App\Http\Controllers\Api\RazorpayController::class, 'getOrderStatus']);

// Invoice API (Single API: Company details always + terms & conditions product_id & project_id wise)
Route::get('/invoice-details', [InvoiceController::class, 'getInvoiceDetails']);

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

    // Sub Modules API
    Route::get('/modules/{id}/sub-modules', [\App\Http\Controllers\Api\SubModuleController::class, 'getByModule']);
    Route::apiResource('sub-modules', \App\Http\Controllers\Api\SubModuleController::class);

    // Products API (store, update, delete)
    Route::apiResource('products', \App\Http\Controllers\Api\ProductController::class)->except(['index', 'show']);

    // Projects API (store, show, update, delete)
    Route::apiResource('projects', \App\Http\Controllers\Api\ProjectController::class)->except(['index']);

    // Project Modules API
    Route::apiResource('project-modules', \App\Http\Controllers\Api\ProjectModuleController::class);

    // Billings API
    Route::apiResource('billings', \App\Http\Controllers\Api\BillingController::class);

    // Memberships API (store, update, delete)
    Route::apiResource('memberships', \App\Http\Controllers\Api\MembershipController::class)->except(['index', 'show']);

    // Clients API (index, show, update, delete)
    Route::get('/clients/upcoming-renewals', [\App\Http\Controllers\Api\ClientController::class, 'upcomingRenewals']);
    Route::post('/clients/{id}/send-active-email', [\App\Http\Controllers\Api\ClientController::class, 'resendActiveEmail']);
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class)->except(['store']);
    
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

    // Settings API (Razorpay, SMTP Email & Company Details: company_name, email, contact, address, logo, signature)
    Route::get('/settings', [\App\Http\Controllers\Api\SettingController::class, 'getSettings']);
    Route::post('/settings', [\App\Http\Controllers\Api\SettingController::class, 'saveSettings']);
    Route::post('/settings/test-email', [\App\Http\Controllers\Api\SettingController::class, 'sendTestEmail']);

    // Terms and Conditions API (CRUD)
    Route::delete('/terms-and-conditions/{id}/item', [TermsAndConditionController::class, 'removeItem']);
    Route::apiResource('terms-and-conditions', TermsAndConditionController::class);
    
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Razorpay Webhook API (MUST be outside auth middleware)
Route::post('/razorpay/webhook', [\App\Http\Controllers\Api\RazorpayController::class, 'webhook']);
