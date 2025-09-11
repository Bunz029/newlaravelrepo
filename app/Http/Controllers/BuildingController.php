<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\LogsActivity;

class BuildingController extends Controller
{
    use LogsActivity;
    public function index(Request $request)
    {
        // Exclude buildings that are pending deletion (they're in trash)
        $query = Building::where('pending_deletion', false)->with(['map', 'employees', 'rooms']);

        // Optional filter by map_id so each layout shows only its buildings
        if ($request->has('map_id')) {
            $query->where('map_id', (int) $request->query('map_id'));
        }

        return $query->get();
    }

    public function getPublished()
    {
        // Only return buildings for the currently active map
        $activeMap = Map::where('is_active', true)->first();
        if (!$activeMap) {
            return collect([]);
        }

        // Get all buildings for the active map that have been published at least once
        // This ensures we show the last published state, not the current unpublished state
        $buildings = Building::where('map_id', $activeMap->id)
                            ->whereNotNull('published_at')
                            ->with(['map', 'rooms' => function($query) {
                                $query->where('is_published', true)
                                      ->where('pending_deletion', false);
                            }])
                            ->get();
        
        // Transform each building to use published data instead of current data
        $publishedBuildings = $buildings->filter(function ($building) {
            // Exclude buildings that are pending deletion AND have been published as deleted
            if ($building->pending_deletion) {
                // Only exclude if the deletion has been published (no published_data means deletion is published)
                return $building->published_data !== null;
            }
            return true;
        })->map(function ($building) {
            if ($building->published_data) {
                // Use published snapshot data
                $publishedBuilding = new Building($building->published_data);
                $publishedBuilding->id = $building->id;
                $publishedBuilding->created_at = $building->created_at;
                $publishedBuilding->updated_at = $building->updated_at;
                
                // Preserve relationships
                $publishedBuilding->setRelation('map', $building->map);
                
                // Use published employee data from snapshot, not current employees
                if (isset($building->published_data['employees'])) {
                    // Convert employee arrays to objects with proper image URLs
                    $employees = collect($building->published_data['employees'])->map(function($employeeData) {
                        // Ensure employee has image path (use default if not set)
                        if (empty($employeeData['employee_image'])) {
                            $employeeData['employee_image'] = 'images/employees/default-profile-icon.png';
                        }
                        return $employeeData;
                    });
                    $publishedBuilding->setRelation('employees', $employees);
                } else {
                    // No published employee data - return empty collection
                    $publishedBuilding->setRelation('employees', collect([]));
                }

                // Use published room data from snapshot, not current rooms
                if (isset($building->published_data['rooms'])) {
                    $rooms = collect($building->published_data['rooms']);
                    $publishedBuilding->setRelation('rooms', $rooms);
                } else {
                    // Use current published rooms if no snapshot data
                    $publishedBuilding->setRelation('rooms', $building->rooms);
                }
                
                return $publishedBuilding;
            } else {
                // Legacy published building without snapshot - use current data only if still published
                if ($building->is_published && !$building->pending_deletion) {
                    // Load current employees and rooms for legacy buildings
                    $building->load(['employees', 'rooms' => function($query) {
                        $query->where('is_published', true)
                              ->where('pending_deletion', false);
                    }]);
                    return $building;
                }
                return null; // Don't include unpublished or deleted buildings without snapshots
            }
        })->filter(); // Remove null values
        
        return $publishedBuildings;
    }

    /**
     * Get a single published building by ID
     */
    public function getPublishedBuilding($id)
    {
        // Ensure building belongs to the active map
        $activeMap = Map::where('is_active', true)->first();
        if (!$activeMap) {
            return response()->json(['message' => 'No active map'], 404);
        }

        $building = Building::where('map_id', $activeMap->id)
                          ->where('is_published', true)
                          ->where('pending_deletion', false)
                          ->where('id', $id)
                          ->with(['map'])
                          ->first();
        
        if (!$building) {
            return response()->json(['message' => 'Published building not found'], 404);
        }
        
        // Use published snapshot if available, otherwise use current data
        if ($building->published_data) {
            $publishedBuilding = new Building($building->published_data);
            $publishedBuilding->id = $building->id;
            $publishedBuilding->created_at = $building->created_at;
            $publishedBuilding->updated_at = $building->updated_at;
            
            // Preserve relationships
            $publishedBuilding->setRelation('map', $building->map);
            
            // Use published employee data from snapshot, not current employees
            if (isset($building->published_data['employees'])) {
                // Convert employee arrays to objects with proper image URLs
                $employees = collect($building->published_data['employees'])->map(function($employeeData) {
                    // Ensure employee has image path (use default if not set)
                    if (empty($employeeData['employee_image'])) {
                            $employeeData['employee_image'] = 'images/employees/default-profile-icon.png';
                    }
                    return $employeeData;
                });
                $publishedBuilding->setRelation('employees', $employees);
            } else {
                // No published employee data - return empty collection
                $publishedBuilding->setRelation('employees', collect([]));
            }
            
            return response()->json($publishedBuilding);
        }
        
        // Legacy published building without snapshot - load current employees
        $building->load('employees');
        return response()->json($building);
    }

    public function store(Request $request)
    {
        Log::info('Building store request received', [
            'has_files' => $request->hasFile('image'),
            'all_data' => $request->all(),
            'employee_count' => $request->input('employee_count'),
            'employees_data' => $request->all()
        ]);

        // Check for duplicate emails first, before other validation
        if ($request->has('employee_count')) {
            $employeeCount = $request->input('employee_count');
            
            if ($employeeCount > 0) {
                // First, collect all employee emails to check for duplicates
                $employeeEmails = [];
                $duplicateEmails = [];
                
                for ($i = 0; $i < $employeeCount; $i++) {
                    $employeeEmail = $request->input("employees.{$i}.email");
                    if ($employeeEmail && !empty(trim($employeeEmail))) {
                        $email = trim($employeeEmail);
                        if (in_array($email, $employeeEmails)) {
                            $duplicateEmails[] = $email;
                        } else {
                            $employeeEmails[] = $email;
                        }
                    }
                }
                
                // Check for existing emails in database
                if (!empty($employeeEmails)) {
                    $existingEmails = \App\Models\Employee::whereIn('email', $employeeEmails)->pluck('email')->toArray();
                    $duplicateEmails = array_merge($duplicateEmails, $existingEmails);
                }
                
                // If there are duplicate emails, return error
                if (!empty($duplicateEmails)) {
                    $duplicateEmailsList = implode(', ', array_unique($duplicateEmails));
                    return response()->json([
                        'error' => 'Duplicate email addresses found',
                        'error_code' => 'DUPLICATE_EMAIL',
                        'message' => "The following email addresses are already in use: {$duplicateEmailsList}. Please use different email addresses for each employee.",
                        'duplicate_emails' => array_unique($duplicateEmails)
                    ], 422);
                }
            }
        }

        $request->validate([
            'map_id' => 'required|exists:maps,id',
            'building_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'services' => 'nullable|string',
            'x_coordinate' => 'required|integer',
            'y_coordinate' => 'required|integer',
            'image' => 'nullable|image|max:5120',
            'modal_image' => 'nullable|image|max:5120',
            'width' => 'nullable|integer',
            'height' => 'nullable|integer',
            'is_published' => 'nullable|in:true,false,1,0',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);

        $data = $request->except(['image', 'modal_image']);
        
        // Convert string boolean to actual boolean for is_published
        if ($request->has('is_published')) {
            $data['is_published'] = filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            Log::info('Image file found:', [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType()
            ]);
            
            $data['image_path'] = $file->store('images/buildings', 'public');
            Log::info('Image stored at: ' . $data['image_path']);
        } else {
            Log::warning('No image file in request');
            $data['image_path'] = null; // Explicitly set to NULL, not string "null"
        }

        if ($request->hasFile('modal_image')) {
            $file = $request->file('modal_image');
            Log::info('Modal image file found:', [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType()
            ]);
            
            $data['modal_image_path'] = $file->store('images/buildings/modal', 'public');
            Log::info('Modal image stored at: ' . $data['modal_image_path']);
        } else {
            Log::warning('No modal image file in request');
            $data['modal_image_path'] = null;
        }

        $building = Building::create($data);
        Log::info('Building created', ['building' => $building->toArray()]);

        // Handle employees if provided
        if ($request->has('employee_count')) {
            $employeeCount = $request->input('employee_count');
            Log::info('Processing employees', ['count' => $employeeCount, 'all_request' => $request->all()]);
            
            if ($employeeCount > 0) {
                for ($i = 0; $i < $employeeCount; $i++) {
                    $employeeName = $request->input("employees.{$i}.name");
                    $employeePosition = $request->input("employees.{$i}.position");
                    $employeeDepartment = $request->input("employees.{$i}.department");
                    $employeeEmail = $request->input("employees.{$i}.email");
                    
                    Log::info("Employee {$i} data", [
                        'name' => $employeeName,
                        'position' => $employeePosition,
                        'department' => $employeeDepartment,
                        'email' => $employeeEmail
                    ]);
                    
                    if ($employeeName && !empty(trim($employeeName))) {
                        $employeeData = [
                            'employee_name' => trim($employeeName),
                            'position' => $employeePosition ? trim($employeePosition) : null,
                            'department' => $employeeDepartment ? trim($employeeDepartment) : null,
                            'email' => $employeeEmail ? trim($employeeEmail) : null,
                            'building_id' => $building->id,
                        ];
                        
                        // Handle employee image if provided
                        if ($request->hasFile("employees.{$i}.image")) {
                            $imageFile = $request->file("employees.{$i}.image");
                            $employeeData['employee_image'] = $imageFile->store('images/employees', 'public');
                        } else {
                            // No image provided - use default
                            $employeeData['employee_image'] = 'images/employees/default-profile-icon.svg';
                        }
                        
                        \App\Models\Employee::create($employeeData);
                    }
                }
            }
        }

        // Load the building with employees for response
        $building->load('employees');

        // Log the activity with a comprehensive snapshot for Add
        $createdDetails = [
            'after' => [
                'building_name' => $building->building_name,
                'description' => $building->description,
                'map_id' => (int) $building->map_id,
                'x_coordinate' => (int) $building->x_coordinate,
                'y_coordinate' => (int) $building->y_coordinate,
                'width' => $building->width,
                'height' => $building->height,
                'latitude' => $building->latitude,
                'longitude' => $building->longitude,
                'services' => $building->services,
                'image_path' => $building->image_path,
                'modal_image_path' => $building->modal_image_path,
                'is_active' => (bool) $building->is_active,
            ],
            'employees' => $building->employees->map(function ($e) {
                return $e->employee_name ?? $e->name ?? $e->email ?? '';
            })->filter()->values()->toArray(),
        ];
        $this->logBuildingActivity('created', $building, $createdDetails);

        return response()->json($building, 201);
    }

    public function show($id)
    {
        $building = Building::findOrFail($id);
        return $building->load(['map', 'employees', 'rooms']);
    }

    public function update(Request $request, $id)
    {
        $building = Building::findOrFail($id);
        // Capture BEFORE snapshot (scalar fields and employees) for accurate diffs
        $building->load('employees');
        $wasPendingDeletion = (bool) $building->pending_deletion;
        $beforeScalar = [
            'building_name' => $building->building_name,
            'description' => $building->description,
            'map_id' => (int) $building->map_id,
            'x_coordinate' => (int) $building->x_coordinate,
            'y_coordinate' => (int) $building->y_coordinate,
            'width' => $building->width,
            'height' => $building->height,
            'latitude' => $building->latitude,
            'longitude' => $building->longitude,
            'services' => $building->services,
            'image_path' => $building->image_path,
            'modal_image_path' => $building->modal_image_path,
            'is_active' => (bool) $building->is_active,
        ];
        $beforeEmployees = $building->employees->map(function ($e) {
            return $e->employee_name ?? $e->name ?? $e->email ?? '';
        })->filter()->values()->toArray();

        Log::info('Building update request received', [
            'id' => $building->id,
            'has_files' => $request->hasFile('image'),
            'all_data' => $request->all(),
            'method' => $request->method(),
            'method_override' => $request->input('_method'),
            'headers' => $request->headers->all()
        ]);

        // Check for duplicate emails first, before other validation
        if ($request->has('employee_count')) {
            $employeeCount = $request->input('employee_count');
            
            if ($employeeCount > 0) {
                // First, collect all employee emails to check for duplicates
                $employeeEmails = [];
                $duplicateEmails = [];
                
                for ($i = 0; $i < $employeeCount; $i++) {
                    $employeeEmail = $request->input("employees.{$i}.email");
                    if ($employeeEmail && !empty(trim($employeeEmail))) {
                        $email = trim($employeeEmail);
                        if (in_array($email, $employeeEmails)) {
                            $duplicateEmails[] = $email;
                        } else {
                            $employeeEmails[] = $email;
                        }
                    }
                }
                
                // Check for existing emails in database (excluding current building's employees)
                if (!empty($employeeEmails)) {
                    $existingEmails = \App\Models\Employee::whereIn('email', $employeeEmails)
                        ->where('building_id', '!=', $building->id)
                        ->pluck('email')
                        ->toArray();
                    $duplicateEmails = array_merge($duplicateEmails, $existingEmails);
                }
                
                // If there are duplicate emails, return error
                if (!empty($duplicateEmails)) {
                    $duplicateEmailsList = implode(', ', array_unique($duplicateEmails));
                    return response()->json([
                        'error' => 'Duplicate email addresses found',
                        'error_code' => 'DUPLICATE_EMAIL',
                        'message' => "The following email addresses are already in use: {$duplicateEmailsList}. Please use different email addresses for each employee.",
                        'duplicate_emails' => array_unique($duplicateEmails)
                    ], 422);
                }
            }
        }

        $request->validate([
            'map_id' => 'sometimes|required|exists:maps,id',
            'building_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'services' => 'nullable|string',
            'x_coordinate' => 'sometimes|required|integer',
            'y_coordinate' => 'sometimes|required|integer',
            'image' => 'nullable|image|max:5120',
            'modal_image' => 'nullable|image|max:5120',
            'width' => 'nullable|integer',
            'height' => 'nullable|integer',
            'is_active' => 'sometimes|required|boolean',
            'is_published' => 'nullable|in:true,false,1,0',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);

        // Remove _method field if it exists (used for method spoofing)
        $data = $request->except(['image', 'modal_image', '_method', '_token']);
        
        // Convert string boolean to actual boolean for is_published
        if ($request->has('is_published')) {
            $data['is_published'] = filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN);
            
            // If this building was previously published, preserve the published_at timestamp
            // so it doesn't disappear from the app when edited
            if (!$data['is_published'] && $building->published_at) {
                // Keep the published_at timestamp even when marking as unpublished
                // This ensures the building remains visible in the app
                unset($data['published_at']); // Don't overwrite the existing timestamp
            }
        }
        
        Log::info('Building data before update:', [
            'building_id' => $building->id,
            'old_data' => $building->toArray(),
            'new_data' => $data
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            Log::info('Image file found for update:', [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType()
            ]);
            
            $data['image_path'] = $file->store('images/buildings', 'public');
            Log::info('New image stored at: ' . $data['image_path']);
        }

        if ($request->hasFile('modal_image')) {
            $file = $request->file('modal_image');
            Log::info('Modal image file found for update:', [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType()
            ]);
            
            $data['modal_image_path'] = $file->store('images/buildings/modal', 'public');
            Log::info('New modal image stored at: ' . $data['modal_image_path']);
        }

        // Force update specific fields to ensure they're saved
        $building->fill($data);
        $result = $building->save();
        
        Log::info('Save result', [
            'success' => $result,
            'changed' => $building->wasChanged(),
            'changed_fields' => $building->getChanges()
        ]);
        
        // Handle employees if provided
        if ($request->has('employee_count')) {
            $employeeCount = (int) $request->input('employee_count');

            // Always delete existing employees when employee_count is provided (including 0) to reflect deletions
            $existingEmployees = \App\Models\Employee::where('building_id', $building->id)->get();
            $existingEmployeeImages = [];
            foreach ($existingEmployees as $emp) {
                if ($emp->employee_image) {
                    $existingEmployeeImages[$emp->id] = $emp->employee_image;
                }
            }
            Log::info('Existing employee images before update', $existingEmployeeImages);

            \App\Models\Employee::where('building_id', $building->id)->delete();

            if ($employeeCount > 0) {
                for ($i = 0; $i < $employeeCount; $i++) {
                    $employeeName = $request->input("employees.{$i}.name");
                    $employeePosition = $request->input("employees.{$i}.position");
                    $employeeDepartment = $request->input("employees.{$i}.department");
                    $employeeEmail = $request->input("employees.{$i}.email");
                    $existingEmployeeId = $request->input("employees.{$i}.id");
                    
                    Log::info("Employee {$i} update data", [
                        'name' => $employeeName,
                        'position' => $employeePosition,
                        'department' => $employeeDepartment,
                        'email' => $employeeEmail,
                        'existing_id' => $existingEmployeeId
                    ]);
                    
                    if ($employeeName && !empty(trim($employeeName))) {
                        $employeeData = [
                            'employee_name' => trim($employeeName),
                            'position' => $employeePosition ? trim($employeePosition) : null,
                            'department' => $employeeDepartment ? trim($employeeDepartment) : null,
                            'email' => $employeeEmail ? trim($employeeEmail) : null,
                            'building_id' => $building->id,
                        ];
                        
                        // Handle employee image
                        if ($request->hasFile("employees.{$i}.image")) {
                            // New image uploaded
                            $imageFile = $request->file("employees.{$i}.image");
                            $employeeData['employee_image'] = $imageFile->store('images/employees', 'public');
                        } elseif ($existingEmployeeId && isset($existingEmployeeImages[$existingEmployeeId])) {
                            // Preserve existing image if no new image uploaded
                            $employeeData['employee_image'] = $existingEmployeeImages[$existingEmployeeId];
                            Log::info("Preserving existing image for employee {$i}", ['image' => $employeeData['employee_image']]);
                        } else {
                            // No image provided - use default
                            $employeeData['employee_image'] = 'images/employees/default-profile-icon.svg';
                        }
                        
                        \App\Models\Employee::create($employeeData);
                    }
                }
            }
        }
        
        // Reload the model to ensure we have fresh data
        $building = Building::findOrFail($id);
        $building->load('employees');

        Log::info('Building updated - final data', [
            'building' => $building->toArray()
        ]);

        $afterScalar = [
            'building_name' => $building->building_name,
            'description' => $building->description,
            'map_id' => (int) $building->map_id,
            'x_coordinate' => (int) $building->x_coordinate,
            'y_coordinate' => (int) $building->y_coordinate,
            'width' => $building->width,
            'height' => $building->height,
            'latitude' => $building->latitude,
            'longitude' => $building->longitude,
            'services' => $building->services,
            'image_path' => $building->image_path,
            'modal_image_path' => $building->modal_image_path,
            'is_active' => (bool) $building->is_active,
        ];

        $afterEmployees = $building->employees->map(function ($e) {
            return $e->employee_name ?? $e->name ?? $e->email ?? '';
        })->filter()->values()->toArray();

        $changeDetails = $this->buildBuildingChangeDetails(
            $beforeScalar,
            $afterScalar,
            $beforeEmployees,
            $afterEmployees
        );

        // If this request effectively restores a pending deletion, log as 'restored'
        if ($wasPendingDeletion && !$building->pending_deletion) {
            $restoredDetails = [
                'building_name' => $building->building_name,
                'description' => $building->description,
                'position' => "({$building->x_coordinate}, {$building->y_coordinate})",
                'services' => $building->services,
                'employees' => $afterEmployees,
            ];
            $this->logBuildingActivity('restored', $building, $restoredDetails);
        } else {
            // Otherwise, normal update log
            $this->logBuildingActivity('updated', $building, $changeDetails);
        }

        return response()->json($building);
    }

    public function destroy($id)
    {
        $building = Building::findOrFail($id);
        $building->load('employees');

        // Mark as pending deletion (do NOT create DeletedItem yet; that happens on publish)
        $building->pending_deletion = true;
        $building->save();

        // Log the activity with detailed information
        $this->logBuildingActivity('deleted', $building, [
            'map_id' => $building->map_id,
            'employee_count' => $building->employees->count(),
            'employees' => $building->employees->map(function ($e) {
                return $e->employee_name ?? $e->name ?? $e->email ?? 'Employee';
            })->filter()->values()->toArray(),
            'coordinates' => "({$building->x_coordinate}, {$building->y_coordinate})",
            'dimensions' => "{$building->width}x{$building->height}",
            'description' => $building->description,
            'services' => $building->services,
            'latitude' => $building->latitude,
            'longitude' => $building->longitude,
            'is_active' => $building->is_active
        ]);

        return response()->json(['message' => 'Building marked for deletion - will be removed from app after publishing'], 200);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:5120'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('images/buildings', 'public');
            return response()->json([
                'path' => $path,
                'url' => asset('storage/' . $path)
            ]);
        }

        return response()->json(['error' => 'No image uploaded'], 400);
    }
}

