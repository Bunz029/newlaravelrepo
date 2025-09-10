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

// Create storage link route (for easy deployment)
Route::get('/linkstorage', function () {
    \Artisan::call('storage:link');
    return response()->json([
        'status' => 'success',
        'message' => 'Storage link created successfully!'
    ]);
});

// Debug upload permissions
Route::get('/debug-upload', function () {
    $mapsDir = storage_path('app/public/maps');
    return response()->json([
        'maps_dir_exists' => file_exists($mapsDir),
        'maps_dir_writable' => is_writable($mapsDir),
        'maps_dir_permissions' => substr(sprintf('%o', fileperms($mapsDir)), -4),
        'storage_dir_exists' => file_exists(storage_path('app/public')),
        'storage_dir_writable' => is_writable(storage_path('app/public')),
        'php_upload_max' => ini_get('upload_max_filesize'),
        'php_post_max' => ini_get('post_max_size'),
        'php_max_files' => ini_get('max_file_uploads'),
        'php_memory_limit' => ini_get('memory_limit'),
        'php_max_execution_time' => ini_get('max_execution_time'),
        'php_max_input_time' => ini_get('max_input_time')
    ]);
});

// Cleanup pending deletions
Route::get('/cleanup-pending-deletions', function () {
    try {
        $pendingMaps = \App\Models\Map::where('pending_deletion', true)->count();
        $pendingBuildings = \App\Models\Building::where('pending_deletion', true)->count();
        
        if ($pendingMaps > 0) {
            \App\Models\Map::where('pending_deletion', true)->delete();
        }
        
        if ($pendingBuildings > 0) {
            \App\Models\Building::where('pending_deletion', true)->delete();
        }
        
        return response()->json([
            'message' => 'Cleanup completed',
            'deleted_maps' => $pendingMaps,
            'deleted_buildings' => $pendingBuildings
        ]);
    } catch (Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

// Serve storage files directly (fallback for Railway)
Route::get('/storage/{path}', function ($path) {
    $filePath = storage_path('app/public/' . $path);
    
    if (!file_exists($filePath)) {
        abort(404, 'File not found: ' . $filePath);
    }
    
    return response()->file($filePath);
})->where('path', '.*');


// User Routes
Route::middleware('auth:sanctum')->get('user', [UserController::class, 'getAuthenticatedUser']);

// Stats Route
Route::get('stats', [StatsController::class, 'getCounts']);

// Note: API routes are defined in routes/api.php
// These web routes are only for health checks and utilities