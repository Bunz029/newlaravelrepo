<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\MapExportController;

// Health check route for Railway
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'ISU E-MAP API is running',
        'version' => '1.0.0',
        'timestamp' => now()
    ]);
});

// Debug route to test activity logging
Route::post('/debug/activity-log', function () {
    try {
        \App\Models\ActivityLog::create([
            'action' => 'test',
            'target_type' => 'debug',
            'target_name' => 'test activity',
            'user_name' => 'debug user'
        ]);
        return response()->json(['status' => 'success', 'message' => 'Activity log created successfully']);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Debug route to test admin authentication
Route::post('/debug/admin-test', function (Request $request) {
    try {
        $email = $request->input('email', 'admin@example.com');
        $admin = \App\Models\Admin::where('email', $email)->first();
        
        if (!$admin) {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin not found',
                'email' => $email
            ], 404);
        }
        
        $passwordCheck = \Illuminate\Support\Facades\Hash::check('password123', $admin->password);
        
        return response()->json([
            'status' => 'success',
            'admin_found' => true,
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'password_check' => $passwordCheck,
            'request_data' => $request->all()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Map Management Routes - Put these first to avoid conflicts with other routes
Route::prefix('map')->group(function () {
    Route::get('/', [MapController::class, 'index']);
    Route::post('/', [MapController::class, 'store']);
    Route::get('/{map}', [MapController::class, 'show']);
    Route::put('/{map}', [MapController::class, 'update']);
    Route::delete('/{map}', [MapController::class, 'destroy']);
    Route::put('/{map}/activate', [MapController::class, 'activate']);
    Route::post('/{map}/save-layout', [MapController::class, 'saveLayout']);
    Route::get('/{map}/layout', [MapController::class, 'getLayout']);
    Route::get('/active', [MapController::class, 'getActive']);
    Route::post('/upload', [MapController::class, 'upload']);
});

// Building Routes
Route::prefix('buildings')->group(function () {
    Route::get('/', [BuildingController::class, 'index']);
    Route::post('/', [BuildingController::class, 'store']);
    Route::get('/{id}', [BuildingController::class, 'show']);
    Route::put('/{id}', [BuildingController::class, 'update']);
    Route::post('/{id}', [BuildingController::class, 'update']);
    Route::delete('/{id}', [BuildingController::class, 'destroy']);
    Route::post('/upload', [BuildingController::class, 'upload']);
    
    // Room routes for specific building
    Route::get('/{id}/rooms', [RoomController::class, 'getRoomsForBuilding']);
    Route::get('/{id}/rooms/admin', [RoomController::class, 'getAdminRoomsForBuilding']);
});

// Room Routes
Route::prefix('rooms')->group(function () {
    Route::get('/{id}', [RoomController::class, 'show']);
    Route::post('/', [RoomController::class, 'store']);
    Route::put('/{id}', [RoomController::class, 'update']);
    Route::delete('/{id}', [RoomController::class, 'destroy']);
});

// Published Content Routes (for Flutter App)
Route::prefix('published')->group(function () {
    Route::get('/maps', [MapController::class, 'getPublished']);
    Route::get('/buildings', [BuildingController::class, 'getPublished']);
    Route::get('/buildings/{id}', [BuildingController::class, 'getPublishedBuilding']);
    Route::get('/rooms/building/{buildingId}', [RoomController::class, 'getRoomsForBuilding']);
    Route::get('/employees', [EmployeeController::class, 'getPublished']);
    Route::get('/employees/building/{buildingId}', [EmployeeController::class, 'getPublishedByBuilding']);
    Route::get('/map/active', [MapController::class, 'getActivePublished']);
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

// Trash Routes
Route::prefix('trash')->group(function () {
    Route::get('/', [TrashController::class, 'index']);
    Route::get('/buildings', [TrashController::class, 'buildings']);
    Route::get('/maps', [TrashController::class, 'maps']);
    Route::post('/{id}/restore', [TrashController::class, 'restore']);
    Route::delete('/{id}/permanent', [TrashController::class, 'permanentDelete']);
    Route::delete('/empty', [TrashController::class, 'empty']);
});

// Publication Routes
Route::prefix('publish')->group(function () {
    // Version endpoint for clients to detect new content
    Route::get('/version', [PublicationController::class, 'version']);
    Route::get('/status', [PublicationController::class, 'status']);
    Route::get('/unpublished', [PublicationController::class, 'unpublished']);
    Route::post('/map/{id}', [PublicationController::class, 'publishMap']);
    Route::post('/building/{id}', [PublicationController::class, 'publishBuilding']);
    Route::post('/employee/{id}', [PublicationController::class, 'publishEmployee']);
    Route::post('/room/{id}', [PublicationController::class, 'publishRoom']);
    Route::post('/maps/all', [PublicationController::class, 'publishAllMaps']);
    Route::post('/buildings/all', [PublicationController::class, 'publishAllBuildings']);
    Route::post('/employees/all', [PublicationController::class, 'publishAllEmployees']);
    Route::post('/rooms/all', [PublicationController::class, 'publishAllRooms']);
    Route::post('/all', [PublicationController::class, 'publishAll']);
    Route::delete('/map/{id}', [PublicationController::class, 'unpublishMap']);
    Route::delete('/building/{id}', [PublicationController::class, 'unpublishBuilding']);
    Route::delete('/employee/{id}', [PublicationController::class, 'unpublishEmployee']);
    Route::post('/revert/map/{id}', [PublicationController::class, 'revertMap']);
    Route::post('/revert/building/{id}', [PublicationController::class, 'revertBuilding']);
    Route::post('/revert/employee/{id}', [PublicationController::class, 'revertEmployee']);
    Route::post('/revert/room/{id}', [PublicationController::class, 'revertRoom']);
});

// User Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'getAuthenticatedUser']);
    Route::post('/logout', [UserController::class, 'logout']);
}); 

// Admin Auth Routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
    });
});

// Activity Log Routes
Route::prefix('activity-logs')->group(function () {
    Route::get('/', [ActivityLogController::class, 'index']);
    Route::post('/', [ActivityLogController::class, 'store']);
    Route::get('/stats', [ActivityLogController::class, 'stats']);
    Route::delete('/clear', [ActivityLogController::class, 'clear']);
});

// Map Export/Import Routes
Route::prefix('map-export')->group(function () {
    Route::get('/{id}', [MapExportController::class, 'exportMap']);
    Route::post('/import', [MapExportController::class, 'importMap']);
});