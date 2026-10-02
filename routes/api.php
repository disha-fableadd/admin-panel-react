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
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class);
    
    // Setups API
    Route::apiResource('setups', \App\Http\Controllers\Api\SetupController::class);
    
    // Staff API
    Route::apiResource('staff', \App\Http\Controllers\Api\StaffController::class);
    
    Route::post('/logout', [AuthController::class, 'logout']);
});
