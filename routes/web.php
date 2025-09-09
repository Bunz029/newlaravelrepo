<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StatsController;

// Health check route for Railway
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'ISU E-MAP API is running',
        'version' => '1.0.0',
        'timestamp' => now()
    ]);
});

// User Routes
Route::middleware('auth:sanctum')->get('user', [UserController::class, 'getAuthenticatedUser']);

// Stats Route
Route::get('stats', [StatsController::class, 'getCounts']);

// Map Management Routes
Route::prefix('map')->group(function () {
    Route::get('/active', [MapController::class, 'getActive']);
    Route::post('/upload', [MapController::class, 'upload']);
    Route::get('/', [MapController::class, 'index']);
    Route::post('/', [MapController::class, 'store']);
    Route::get('/{map}', [MapController::class, 'show']);
    Route::put('/{map}', [MapController::class, 'update']);
    Route::delete('/{map}', [MapController::class, 'destroy']);
    Route::put('/{map}/activate', [MapController::class, 'activate']);
});

// Building Routes
Route::prefix('buildings')->group(function () {
    Route::get('/', [BuildingController::class, 'index']);
    Route::post('/', [BuildingController::class, 'store']);
    Route::get('/{id}', [BuildingController::class, 'show']);
    Route::put('/{id}', [BuildingController::class, 'update']);
    Route::delete('/{id}', [BuildingController::class, 'destroy']);
});

// Faculty Routes (Legacy - will be replaced by employees)
Route::prefix('faculty')->group(function () {
    Route::get('/', [FacultyController::class, 'index']);
    Route::post('/', [FacultyController::class, 'store']);
    Route::get('/building/{buildingId}', [FacultyController::class, 'getByBuilding']);
    Route::get('/{id}', [FacultyController::class, 'show']);
    Route::put('/{id}', [FacultyController::class, 'update']);
    Route::delete('/{id}', [FacultyController::class, 'destroy']);
});

// Employee Routes
Route::prefix('employees')->group(function () {
    Route::get('/', [EmployeeController::class, 'index']);
    Route::post('/', [EmployeeController::class, 'store']);
    Route::get('/building/{buildingId}', [EmployeeController::class, 'getByBuilding']);
    Route::get('/{id}', [EmployeeController::class, 'show']);
    Route::put('/{id}', [EmployeeController::class, 'update']);
    Route::delete('/{id}', [EmployeeController::class, 'destroy']);
});