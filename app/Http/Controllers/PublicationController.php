<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Map;
use App\Models\Employee;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use App\Traits\LogsActivity;
use Exception;

class PublicationController extends Controller
{
    use LogsActivity;
    /**
     * Get publication status summary
     */
    public function status(): JsonResponse
    {
        try {
            // Count maps that have unpublished changes
            $allMaps = Map::all();
            $unpublishedMapsCount = $allMaps->filter(function ($map) {
                if (!$map->is_published) return true;
                try {
                    if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) return true;
                } catch (Exception $e) {
                    // Column doesn't exist yet, skip this check
                }
                if ($map->published_data) {
                    $currentData = $map->only(['name', 'image_path', 'width', 'height', 'is_active']);
                    return $currentData != $map->published_data;
                }
                return false;
            })->count();
            
            // Limit scope to active map's buildings to avoid counting unrelated items
            $activeMap = Map::where('is_active', true)->first();
            $activeMapId = $activeMap?->id;

            // Count buildings that have unpublished changes (only for active map)
            $allBuildings = $activeMapId ? Building::where('map_id', $activeMapId)->get() : collect();
            $unpublishedBuildingsCount = $allBuildings->filter(function ($building) {
                if (!$building->is_published) return true;
                if ($building->pending_deletion) return true;
                if ($building->published_data) {
                    $comparisonKeys = [
                        'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                        'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                        'map_id', 'latitude', 'longitude'
                    ];
                    $currentData = $building->only($comparisonKeys);
                    $publishedSubset = [];
                    if (is_array($building->published_data)) {
                        $publishedSubset = array_intersect_key($building->published_data, array_flip($comparisonKeys));
                    }

                    // Detect employee-only changes as unpublished as well (ignore volatile fields)
                    $building->loadMissing('employees');
                    $currentEmployees = $building->employees->map(function ($employee) {
                        return [
                            'employee_name' => (string) $employee->employee_name,
                            'position' => $employee->position ? (string) $employee->position : null,
                            'department' => $employee->department ? (string) $employee->department : null,
                            'email' => $employee->email ? (string) $employee->email : null,
                            'contact_number' => $employee->contact_number ? (string) $employee->contact_number : null,
                            'employee_image' => $employee->employee_image ? (string) $employee->employee_image : null,
                        ];
                    })->sortBy(function ($e) { return strtolower(($e['employee_name'] ?? '') . '|' . ($e['email'] ?? '')); })->values()->toArray();

                    $publishedEmployees = [];
                    if (isset($building->published_data['employees']) && is_array($building->published_data['employees'])) {
                        $publishedEmployees = collect($building->published_data['employees'])
                            ->map(function ($employee) {
                                return [
                                    'employee_name' => isset($employee['employee_name']) ? (string) $employee['employee_name'] : null,
                                    'position' => isset($employee['position']) ? (string) $employee['position'] : null,
                                    'department' => isset($employee['department']) ? (string) $employee['department'] : null,
                                    'email' => isset($employee['email']) ? (string) $employee['email'] : null,
                                    'contact_number' => isset($employee['contact_number']) ? (string) $employee['contact_number'] : null,
                                    'employee_image' => isset($employee['employee_image']) ? (string) $employee['employee_image'] : null,
                                ];
                            })
                            ->sortBy(function ($e) { return strtolower(($e['employee_name'] ?? '') . '|' . ($e['email'] ?? '')); })
                            ->values()
                            ->toArray();
                    }

                    $structureChanged = json_encode($currentData) !== json_encode($publishedSubset);
                    $employeesChanged = json_encode($currentEmployees) !== json_encode($publishedEmployees);
                    return $structureChanged || $employeesChanged;
                }
                return false;
            })->count();
            
            // Count employees that have unpublished changes
            $allEmployees = Employee::all();
            $unpublishedEmployeesCount = $allEmployees->filter(function ($employee) {
                if (!$employee->is_published) return true;
                if ($employee->published_data) {
                    $currentData = $employee->only([
                        'employee_name', 'position', 'department', 'email', 'contact_number',
                        'employee_image', 'building_id'
                    ]);
                    return $currentData != $employee->published_data;
                }
                return false;
            })->count();
            
            $publishedMaps = Map::whereNotNull('published_at')->count();
            $publishedBuildings = Building::whereNotNull('published_at')->count();
            $publishedEmployees = Employee::whereNotNull('published_at')->count();
            
            // Count rooms that have unpublished changes
            $unpublishedRoomsCount = $this->getUnpublishedRoomsCount();
            $publishedRooms = Room::where('is_published', true)->count();
            
            $lastPublishedMap = Map::whereNotNull('published_at')
                ->orderBy('published_at', 'desc')
                ->first();
                
            $lastPublishedBuilding = Building::whereNotNull('published_at')
                ->orderBy('published_at', 'desc')
                ->first();

            return response()->json([
                'unpublished' => [
                    'maps' => $unpublishedMapsCount,
                    'buildings' => $unpublishedBuildingsCount,
                    'employees' => $unpublishedEmployeesCount,
                    'rooms' => $unpublishedRoomsCount,
                    // Align badge count to what the modal can publish (maps + buildings + rooms)
                    'total' => $unpublishedMapsCount + $unpublishedBuildingsCount + $unpublishedRoomsCount
                ],
                'published' => [
                    'maps' => $publishedMaps,
                    'buildings' => $publishedBuildings,
                    'employees' => $publishedEmployees,
                    'rooms' => $publishedRooms,
                    'total' => $publishedMaps + $publishedBuildings + $publishedEmployees + $publishedRooms
                ],
                'last_published' => [
                    'map' => $lastPublishedMap ? [
                        'name' => $lastPublishedMap->name,
                        'published_at' => $lastPublishedMap->published_at,
                        'published_by' => $lastPublishedMap->published_by
                    ] : null,
                    'building' => $lastPublishedBuilding ? [
                        'name' => $lastPublishedBuilding->building_name,
                        'published_at' => $lastPublishedBuilding->published_at,
                        'published_by' => $lastPublishedBuilding->published_by
                    ] : null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch publication status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unpublished items
     */
    public function unpublished(): JsonResponse
    {
        try {
            // Find maps that are either unpublished OR have changes from their published snapshot
            $allMaps = Map::orderBy('updated_at', 'desc')->get();
            $unpublishedMaps = $allMaps->filter(function ($map) {
                // Include if never published
                if (!$map->is_published) {
                    return true;
                }
                
                // Include if marked for deletion
                try {
                    if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) {
                        return true;
                    }
                } catch (Exception $e) {
                    // Column doesn't exist yet, skip this check
                }
                
                // Include if published but has changes from snapshot
                if ($map->published_data) {
                    $currentData = $map->only(['name', 'image_path', 'width', 'height', 'is_active']);
                    return $currentData != $map->published_data;
                }
                
                // Include legacy published items that might have changes
                return false;
            })->values();
                
            // Find buildings that are either unpublished OR have changes from their published snapshot
            $activeMap = Map::where('is_active', true)->first();
            $activeMapId = $activeMap?->id;
            $allBuildings = $activeMapId
                ? Building::with(['map', 'employees'])
                    ->where('map_id', $activeMapId)
                ->orderBy('updated_at', 'desc')
                    ->get()
                : collect();
            $unpublishedBuildings = $allBuildings->filter(function ($building) {
                // Include if never published
                if (!$building->is_published) {
                    return true;
                }
                
                // Include if pending deletion (deletion not yet published)
                if ($building->pending_deletion) {
                    return true;
                }
                
                // Include if published but has changes from snapshot
                if ($building->published_data) {
                    $comparisonKeys = [
                        'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                        'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                        'map_id', 'latitude', 'longitude'
                    ];
                    $currentData = $building->only($comparisonKeys);
                    $publishedSubset = [];
                    if (is_array($building->published_data)) {
                        $publishedSubset = array_intersect_key($building->published_data, array_flip($comparisonKeys));
                    }

                    // Detect employee-only changes as unpublished (ignore volatile fields)
                    $building->loadMissing('employees');
                    $currentEmployees = $building->employees->map(function ($employee) {
                        return [
                            'employee_name' => (string) $employee->employee_name,
                            'position' => $employee->position ? (string) $employee->position : null,
                            'department' => $employee->department ? (string) $employee->department : null,
                            'email' => $employee->email ? (string) $employee->email : null,
                            'contact_number' => $employee->contact_number ? (string) $employee->contact_number : null,
                            'employee_image' => $employee->employee_image ? (string) $employee->employee_image : null,
                        ];
                    })->sortBy(function ($e) { return strtolower(($e['employee_name'] ?? '') . '|' . ($e['email'] ?? '')); })->values()->toArray();

                    $publishedEmployees = [];
                    if (isset($building->published_data['employees'])) {
                        $publishedEmployees = collect($building->published_data['employees'])
                            ->map(function ($employee) {
                                return [
                                    'employee_name' => isset($employee['employee_name']) ? (string) $employee['employee_name'] : null,
                                    'position' => isset($employee['position']) ? (string) $employee['position'] : null,
                                    'department' => isset($employee['department']) ? (string) $employee['department'] : null,
                                    'email' => isset($employee['email']) ? (string) $employee['email'] : null,
                                    'contact_number' => isset($employee['contact_number']) ? (string) $employee['contact_number'] : null,
                                    'employee_image' => isset($employee['employee_image']) ? (string) $employee['employee_image'] : null,
                                ];
                            })
                            ->sortBy(function ($e) { return strtolower(($e['employee_name'] ?? '') . '|' . ($e['email'] ?? '')); })
                            ->values()
                            ->toArray();
                    }

                    $structureChanged = json_encode($currentData) !== json_encode($publishedSubset);
                    $employeesChanged = json_encode($currentEmployees) !== json_encode($publishedEmployees);
                    return $structureChanged || $employeesChanged;
                }
                
                // Include legacy published items that might have changes
                return false;
            })->values();

            // Find rooms that are unpublished
            $unpublishedRooms = Room::where('is_published', false)
                ->where('pending_deletion', false)
                ->with('building')
                ->orderBy('updated_at', 'desc')
                ->get();

            return response()->json([
                'maps' => $unpublishedMaps,
                'buildings' => $unpublishedBuildings,
                'rooms' => $unpublishedRooms
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch unpublished items',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish a specific map
     */
    public function publishMap($id): JsonResponse
    {
        try {
            $map = Map::findOrFail($id);
            
            // Handle map deletion vs update
            try {
                if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) {
                // Map is marked for deletion - actually delete it
                $mapName = $map->name;
                $buildingCount = $map->buildings->count();
                
                // Log the published deletion activity before deleting
                $this->logActivity('published_deletion', 'map', $map->id, $mapName, [
                    'building_count' => $buildingCount,
                    'published_by' => Auth::user()?->name ?? 'Admin'
                ]);
                
                // Actually delete the map
                $map->delete();
                
                return response()->json([
                    'message' => 'Map deletion published successfully',
                    'deleted_map' => $mapName
                ]);
                }
            } catch (Exception $e) {
                // Column doesn't exist yet, proceed with normal publish
            }
            
            // Normal publish logic
            if (true) {
                // Store current data as published snapshot
                $map->published_data = $map->only([
                    'name', 'image_path', 'width', 'height', 'is_active'
                ]);
                
                $map->is_published = true;
                $map->published_at = now();
                $map->published_by = Auth::user()?->name ?? 'Admin';
                $map->save();

                return response()->json([
                    'message' => 'Map published successfully',
                    'map' => $map
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish map',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish a specific building
     */
    public function publishBuilding($id): JsonResponse
    {
        try {
            $building = Building::findOrFail($id);
            
            // Handle building deletion vs update
            if ($building->pending_deletion) {
                // Building is marked for deletion - remove published data to delete from app
                $building->published_data = null;
                $building->is_published = false;
                $building->published_at = now();
                $building->published_by = Auth::user()?->name ?? 'Admin';
                $building->save();
                
                // Log the published deletion activity
                $this->logActivity('published_deletion', 'building', $building->id, $building->building_name, [
                    'map_id' => $building->map_id,
                    'employee_count' => $building->employees->count(),
                    'published_by' => Auth::user()?->name ?? 'Admin'
                ]);

                // Actually delete the building record
                $building->delete();
                
                return response()->json([
                    'message' => 'Building deletion published - removed from app',
                    'action' => 'deleted'
                ]);
            } else {
                // Regular building update - store current data as published snapshot
                $publishedData = $building->only([
                'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                'map_id', 'latitude', 'longitude'
            ]);
                
                // Include current employee data in the published snapshot
                $building->load('employees');
                $publishedData['employees'] = $building->employees->map(function($employee) {
                    return [
                        'id' => $employee->id,
                        'employee_name' => $employee->employee_name,
                        'position' => $employee->position,
                        'department' => $employee->department,
                        'email' => $employee->email,
                        'contact_number' => $employee->contact_number,
                        'employee_image' => $employee->employee_image ?: 'images/employees/default-profile-icon.png',
                        'building_id' => $employee->building_id,
                        'created_at' => $employee->created_at,
                        'updated_at' => $employee->updated_at
                    ];
                })->toArray();
                
                $building->published_data = $publishedData;
            
            $building->is_published = true;
            $building->published_at = now();
                $building->published_by = Auth::user()?->name ?? 'Admin';
            $building->save();

                // Log the published building activity
                $this->logActivity('published_building', 'building', $building->id, $building->building_name, [
                    'map_id' => $building->map_id,
                    'employee_count' => $building->employees->count(),
                    'published_by' => Auth::user()?->name ?? 'Admin'
                ]);

            return response()->json([
                'message' => 'Building published successfully',
                    'building' => $building,
                    'action' => 'updated'
            ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish building',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish all unpublished maps
     */
    public function publishAllMaps(): JsonResponse
    {
        try {
            $publishedBy = Auth::user()?->name ?? 'Admin';
            $publishedAt = now();
            
            // Find maps that have unpublished changes
            $allMaps = Map::all();
            $unpublishedMaps = $allMaps->filter(function ($map) {
                if (!$map->is_published) return true;
                try {
                    if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) return true;
                } catch (Exception $e) {
                    // Column doesn't exist yet, skip this check
                }
                if ($map->published_data) {
                    $currentData = $map->only(['name', 'image_path', 'width', 'height', 'is_active']);
                    return $currentData != $map->published_data;
                }
                return false;
            });
            
            $count = 0;
            foreach ($unpublishedMaps as $map) {
                try {
                    if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) {
                        // Map is marked for deletion - actually delete it
                        $mapName = $map->name;
                        $buildingCount = $map->buildings->count();
                        
                        // Log the published deletion activity before deleting
                        $this->logActivity('published_deletion', 'map', $map->id, $mapName, [
                            'building_count' => $buildingCount,
                            'published_by' => $publishedBy
                        ]);
                        
                        // Actually delete the map
                        $map->delete();
                    } else {
                        // Normal publish
                        $map->published_data = $map->only([
                            'name', 'image_path', 'width', 'height', 'is_active'
                        ]);
                        $map->is_published = true;
                        $map->published_at = $publishedAt;
                        $map->published_by = $publishedBy;
                        $map->save();
                    }
                } catch (Exception $e) {
                    // Column doesn't exist yet, proceed with normal publish
                    $map->published_data = $map->only([
                        'name', 'image_path', 'width', 'height', 'is_active'
                    ]);
                    $map->is_published = true;
                    $map->published_at = $publishedAt;
                    $map->published_by = $publishedBy;
                    $map->save();
                }
                $count++;
            }

            return response()->json([
                'message' => "Successfully published {$count} maps",
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish maps',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish all unpublished buildings
     */
    public function publishAllBuildings(): JsonResponse
    {
        try {
            $publishedBy = Auth::user()?->name ?? 'Admin';
            $publishedAt = now();
            
            // Find buildings that have unpublished changes (only for active map)
            $activeMap = Map::where('is_active', true)->first();
            $activeMapId = $activeMap?->id;
            $allBuildings = $activeMapId ? Building::where('map_id', $activeMapId)->get() : collect();
            $unpublishedBuildings = $allBuildings->filter(function ($building) {
                if (!$building->is_published) return true;
                if ($building->pending_deletion) return true;
                if ($building->published_data) {
                    $comparisonKeys = [
                        'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                        'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                        'map_id', 'latitude', 'longitude'
                    ];
                    $currentData = $building->only($comparisonKeys);
                    $publishedSubset = [];
                    if (is_array($building->published_data)) {
                        $publishedSubset = array_intersect_key($building->published_data, array_flip($comparisonKeys));
                    }

                    // Detect employee-only changes as unpublished
                    $building->loadMissing('employees');
                    $currentEmployees = $building->employees->map(function ($employee) {
                        return [
                            'id' => $employee->id,
                            'employee_name' => $employee->employee_name,
                            'position' => $employee->position,
                            'department' => $employee->department,
                            'email' => $employee->email,
                            'contact_number' => $employee->contact_number,
                            'employee_image' => $employee->employee_image ?: 'images/employees/default-profile-icon.svg',
                            'building_id' => $employee->building_id,
                        ];
                    })->sortBy('id')->values()->toArray();

                    $publishedEmployees = [];
                    if (isset($building->published_data['employees']) && is_array($building->published_data['employees'])) {
                        $publishedEmployees = collect($building->published_data['employees'])
                            ->map(function ($employee) {
                                return [
                                    'id' => $employee['id'] ?? null,
                                    'employee_name' => $employee['employee_name'] ?? null,
                                    'position' => $employee['position'] ?? null,
                                    'department' => $employee['department'] ?? null,
                                    'email' => $employee['email'] ?? null,
                                    'contact_number' => $employee['contact_number'] ?? null,
                                    'employee_image' => $employee['employee_image'] ?? null,
                                    'building_id' => $employee['building_id'] ?? null,
                                ];
                            })
                            ->sortBy('id')
                            ->values()
                            ->toArray();
                    }

                    $structureChanged = json_encode($currentData) !== json_encode($publishedSubset);
                    $employeesChanged = json_encode($currentEmployees) !== json_encode($publishedEmployees);
                    return $structureChanged || $employeesChanged;
                }
                return false;
            });
            
            $count = 0;
            $deletedCount = 0;
            foreach ($unpublishedBuildings as $building) {
                if ($building->pending_deletion) {
                    // Actually delete buildings that are pending deletion
                    $building->delete();
                    $deletedCount++;
                } else {
                    // Publish buildings with updates
                    $publishedData = $building->only([
                        'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                        'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                        'map_id', 'latitude', 'longitude'
                    ]);
                    
                    // Include current employee data in the published snapshot
                    $building->load('employees');
                    $publishedData['employees'] = $building->employees->map(function($employee) {
                        return [
                            'id' => $employee->id,
                            'employee_name' => $employee->employee_name,
                            'position' => $employee->position,
                            'department' => $employee->department,
                            'email' => $employee->email,
                            'contact_number' => $employee->contact_number,
                            'employee_image' => $employee->employee_image ?: 'images/employees/default-profile-icon.svg',
                            'building_id' => $employee->building_id,
                            'created_at' => $employee->created_at,
                            'updated_at' => $employee->updated_at
                        ];
                    })->toArray();
                    
                    $building->published_data = $publishedData;
                    
                    $building->is_published = true;
                    $building->published_at = $publishedAt;
                    $building->published_by = $publishedBy;
                    $building->save();
                    $count++;
                }
            }

            $message = "Successfully published {$count} buildings";
            if ($deletedCount > 0) {
                $message .= ", deleted {$deletedCount} buildings";
            }

            return response()->json([
                'message' => $message,
                'count' => $count,
                'deleted' => $deletedCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish buildings',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish all unpublished items (maps and buildings)
     */
    public function publishAll(): JsonResponse
    {
        try {
            DB::beginTransaction();
            
            $publishedBy = Auth::user()?->name ?? 'Admin';
            $publishedAt = now();
            
            // Find maps that have unpublished changes (same logic as unpublished() method)
            $allMaps = Map::all();
            $unpublishedMaps = $allMaps->filter(function ($map) {
                if (!$map->is_published) return true;
                try {
                    if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) return true;
                } catch (Exception $e) {
                    // Column doesn't exist yet, skip this check
                }
                if ($map->published_data) {
                    $currentData = $map->only(['name', 'image_path', 'width', 'height', 'is_active']);
                    return $currentData != $map->published_data;
                }
                return false;
            });
            
            $mapCount = 0;
            foreach ($unpublishedMaps as $map) {
                try {
                    if (Schema::hasColumn('maps', 'pending_deletion') && $map->pending_deletion) {
                        // Map is marked for deletion - actually delete it
                        $mapName = $map->name;
                        $buildingCount = $map->buildings->count();
                        
                        // Log the published deletion activity before deleting
                        $this->logActivity('published_deletion', 'map', $map->id, $mapName, [
                            'building_count' => $buildingCount,
                            'published_by' => $publishedBy
                        ]);
                        
                        // Actually delete the map
                        $map->delete();
                    } else {
                        // Normal publish
                        $map->published_data = $map->only([
                            'name', 'image_path', 'width', 'height', 'is_active'
                        ]);
                        $map->is_published = true;
                        $map->published_at = $publishedAt;
                        $map->published_by = $publishedBy;
                        $map->save();
                    }
                } catch (Exception $e) {
                    // Column doesn't exist yet, proceed with normal publish
                    $map->published_data = $map->only([
                        'name', 'image_path', 'width', 'height', 'is_active'
                    ]);
                    $map->is_published = true;
                    $map->published_at = $publishedAt;
                    $map->published_by = $publishedBy;
                    $map->save();
                }
                $mapCount++;
            }
            
            // Find buildings that have unpublished changes (same logic as unpublished() method) for active map only
            $activeMap = Map::where('is_active', true)->first();
            $activeMapId = $activeMap?->id;
            $allBuildings = $activeMapId ? Building::where('map_id', $activeMapId)->get() : collect();
            $unpublishedBuildings = $allBuildings->filter(function ($building) {
                if (!$building->is_published) return true;
                if ($building->pending_deletion) return true;
                if ($building->published_data) {
                    $comparisonKeys = [
                        'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                        'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                        'map_id', 'latitude', 'longitude'
                    ];
                    $currentData = $building->only($comparisonKeys);
                    $publishedSubset = [];
                    if (is_array($building->published_data)) {
                        $publishedSubset = array_intersect_key($building->published_data, array_flip($comparisonKeys));
                    }

                    // Detect employee-only changes as unpublished
                    $building->loadMissing('employees');
                    $currentEmployees = $building->employees->map(function ($employee) {
                        return [
                            'id' => $employee->id,
                            'employee_name' => $employee->employee_name,
                            'position' => $employee->position,
                            'department' => $employee->department,
                            'email' => $employee->email,
                            'contact_number' => $employee->contact_number,
                            'employee_image' => $employee->employee_image ?: 'images/employees/default-profile-icon.svg',
                            'building_id' => $employee->building_id,
                        ];
                    })->sortBy('id')->values()->toArray();

                    $publishedEmployees = [];
                    if (isset($building->published_data['employees']) && is_array($building->published_data['employees'])) {
                        $publishedEmployees = collect($building->published_data['employees'])
                            ->map(function ($employee) {
                                return [
                                    'id' => $employee['id'] ?? null,
                                    'employee_name' => $employee['employee_name'] ?? null,
                                    'position' => $employee['position'] ?? null,
                                    'department' => $employee['department'] ?? null,
                                    'email' => $employee['email'] ?? null,
                                    'contact_number' => $employee['contact_number'] ?? null,
                                    'employee_image' => $employee['employee_image'] ?? null,
                                    'building_id' => $employee['building_id'] ?? null,
                                ];
                            })
                            ->sortBy('id')
                            ->values()
                            ->toArray();
                    }

                    $structureChanged = json_encode($currentData) !== json_encode($publishedSubset);
                    $employeesChanged = json_encode($currentEmployees) !== json_encode($publishedEmployees);
                    return $structureChanged || $employeesChanged;
                }
                return false;
            });
            
            $buildingCount = 0;
            $deletedCount = 0;
            
            foreach ($unpublishedBuildings as $building) {
                if ($building->pending_deletion) {
                    // Actually delete buildings that are pending deletion
                    $building->delete();
                    $deletedCount++;
                } else {
                    // Publish buildings with updates
                    $publishedData = $building->only([
                        'building_name', 'description', 'services', 'image_path', 'modal_image_path',
                        'x_coordinate', 'y_coordinate', 'width', 'height', 'is_active',
                        'map_id', 'latitude', 'longitude'
                    ]);
                    
                    // Include current employee data in the published snapshot
                    $building->load('employees');
                    $publishedData['employees'] = $building->employees->map(function($employee) {
                        return [
                            'id' => $employee->id,
                            'employee_name' => $employee->employee_name,
                            'position' => $employee->position,
                            'department' => $employee->department,
                            'email' => $employee->email,
                            'contact_number' => $employee->contact_number,
                            'employee_image' => $employee->employee_image ?: 'images/employees/default-profile-icon.svg',
                            'building_id' => $employee->building_id,
                            'created_at' => $employee->created_at,
                            'updated_at' => $employee->updated_at
                        ];
                    })->toArray();
                    
                    $building->published_data = $publishedData;
                    
                    $building->is_published = true;
                    $building->published_at = $publishedAt;
                    $building->published_by = $publishedBy;
                    $building->save();
                    $buildingCount++;
                }
            }

            DB::commit();

            $message = "Successfully published {$mapCount} maps and {$buildingCount} buildings";
            if ($deletedCount > 0) {
                $message .= ", deleted {$deletedCount} buildings";
            }
            
            // Publish all unpublished rooms
            $unpublishedRooms = Room::where('is_published', false)
                ->where('pending_deletion', false)
                ->get();
            
            $roomCount = 0;
            foreach ($unpublishedRooms as $room) {
                $publishedData = $room->only([
                    'name', 'description', 'panorama_image_path', 'thumbnail_path', 'building_id'
                ]);
                
                $room->update([
                    'is_published' => true,
                    'published_data' => $publishedData
                ]);
                $roomCount++;
            }
            
            if ($roomCount > 0) {
                $message .= ", published {$roomCount} rooms";
            }
            
            // Log the published activity
            $this->logActivity('published', 'system', null, 'All Pending Changes', [
                'maps_published' => $mapCount,
                'buildings_published' => $buildingCount,
                'buildings_deleted' => $deletedCount,
                'rooms_published' => $roomCount,
                'total_published' => $mapCount + $buildingCount + $roomCount,
                'published_by' => $publishedBy
            ]);
            
            return response()->json([
                'message' => $message,
                'maps_published' => $mapCount,
                'buildings_published' => $buildingCount,
                'buildings_deleted' => $deletedCount,
                'rooms_published' => $roomCount,
                'total_published' => $mapCount + $buildingCount + $roomCount
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'error' => 'Failed to publish all items',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unpublish a specific map
     */
    public function unpublishMap($id): JsonResponse
    {
        try {
            $map = Map::findOrFail($id);
            
            $map->is_published = false;
            $map->published_at = null;
            $map->published_by = null;
            $map->save();

            return response()->json([
                'message' => 'Map unpublished successfully',
                'map' => $map
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to unpublish map',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unpublish a specific building
     */
    public function unpublishBuilding($id): JsonResponse
    {
        try {
            $building = Building::findOrFail($id);
            
            $building->is_published = false;
            $building->published_at = null;
            $building->published_by = null;
            $building->save();

            return response()->json([
                'message' => 'Building unpublished successfully',
                'building' => $building
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to unpublish building',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revert a building to its published state
     */
    public function revertBuilding($id): JsonResponse
    {
        try {
            $building = Building::findOrFail($id);
            
            if (!$building->published_data) {
                return response()->json([
                    'error' => 'No published data found to revert to'
                ], 400);
            }
            
            // Revert to published state
            $building->fill($building->published_data);
            $building->is_published = true; // Keep it published since we're reverting to published state
            $building->save();

            return response()->json([
                'message' => 'Building reverted to published state successfully',
                'building' => $building
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to revert building',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revert a map to its published state
     */
    public function revertMap($id): JsonResponse
    {
        try {
            $map = Map::findOrFail($id);
            
            if (!$map->published_data) {
                // New map that was never published - just delete it
                $mapName = $map->name;
                $map->delete();
                
                return response()->json([
                    'message' => 'New map deleted successfully',
                    'deleted_map' => $mapName
                ]);
            }
            
            // Revert to published state
            $map->fill($map->published_data);
            $map->is_published = true; // Keep it published since we're reverting to published state
            $map->save();

            return response()->json([
                'message' => 'Map reverted to published state successfully',
                'map' => $map
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to revert map',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish a specific employee
     */
    public function publishEmployee($id): JsonResponse
    {
        try {
            $employee = Employee::findOrFail($id);
            
            // Store current data as published snapshot
            $employee->published_data = $employee->only([
                'employee_name', 'position', 'department', 'email', 'contact_number',
                'employee_image', 'building_id'
            ]);
            
            $employee->is_published = true;
            $employee->published_at = now();
            $employee->published_by = Auth::user()?->name ?? 'Admin';
            $employee->save();

            // Log the published employee activity
            $this->logActivity('published_employee', 'employee', $employee->id, $employee->employee_name, [
                'building_id' => $employee->building_id,
                'published_by' => Auth::user()?->name ?? 'Admin'
            ]);

            return response()->json([
                'message' => 'Employee published successfully',
                'employee' => $employee
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish employee',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish all unpublished employees
     */
    public function publishAllEmployees(): JsonResponse
    {
        try {
            $publishedBy = Auth::user()?->name ?? 'Admin';
            $publishedAt = now();
            
            // Find employees that have unpublished changes
            $allEmployees = Employee::all();
            $unpublishedEmployees = $allEmployees->filter(function ($employee) {
                if (!$employee->is_published) return true;
                if ($employee->published_data) {
                    $currentData = $employee->only([
                        'employee_name', 'position', 'department', 'email', 'contact_number',
                        'employee_image', 'building_id'
                    ]);
                    return $currentData != $employee->published_data;
                }
                return false;
            });
            
            $count = 0;
            foreach ($unpublishedEmployees as $employee) {
                $employee->published_data = $employee->only([
                    'employee_name', 'position', 'department', 'email', 'contact_number',
                    'employee_image', 'building_id'
                ]);
                $employee->is_published = true;
                $employee->published_at = $publishedAt;
                $employee->published_by = $publishedBy;
                $employee->save();
                $count++;
            }

            return response()->json([
                'message' => "Successfully published {$count} employees",
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish employees',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unpublish a specific employee
     */
    public function unpublishEmployee($id): JsonResponse
    {
        try {
            $employee = Employee::findOrFail($id);
            
            $employee->is_published = false;
            $employee->published_at = null;
            $employee->published_by = null;
            $employee->save();

            return response()->json([
                'message' => 'Employee unpublished successfully',
                'employee' => $employee
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to unpublish employee',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revert an employee to its published state
     */
    public function revertEmployee($id): JsonResponse
    {
        try {
            $employee = Employee::findOrFail($id);
            
            if (!$employee->published_data) {
                return response()->json([
                    'error' => 'No published data found to revert to'
                ], 400);
            }
            
            // Revert to published state
            $employee->fill($employee->published_data);
            $employee->is_published = true; // Keep it published since we're reverting to published state
            $employee->save();

            return response()->json([
                'message' => 'Employee reverted to published state successfully',
                'employee' => $employee
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to revert employee',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish a specific room
     */
    public function publishRoom($roomId): JsonResponse
    {
        try {
            $room = Room::findOrFail($roomId);
            
            // Clean up old published images if they exist and are different from current
            if ($room->published_data && is_array($room->published_data)) {
                $oldPanoramaPath = $room->published_data['panorama_image_path'] ?? null;
                $oldThumbnailPath = $room->published_data['thumbnail_path'] ?? null;
                $currentPanoramaPath = $room->panorama_image_path;
                $currentThumbnailPath = $room->thumbnail_path;
                
                // Delete old images if they're different from current ones
                if ($oldPanoramaPath && $oldPanoramaPath !== $currentPanoramaPath) {
                    Storage::disk('public')->delete($oldPanoramaPath);
                }
                if ($oldThumbnailPath && $oldThumbnailPath !== $currentThumbnailPath) {
                    Storage::disk('public')->delete($oldThumbnailPath);
                }
            }
            
            // Create published data snapshot
            $publishedData = $room->only([
                'name', 'description', 'panorama_image_path', 'thumbnail_path', 'building_id'
            ]);
            
            $room->update([
                'is_published' => true,
                'published_data' => $publishedData,
                'pending_deletion' => false
            ]);

            // Activity log: published (room)
            $this->logActivity('published', 'room', $room->id, $room->name, [
                'name' => $room->name,
                'image' => $room->panorama_image_path
            ]);

            return response()->json([
                'message' => 'Room published successfully',
                'room' => $room
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to publish room'], 500);
        }
    }

    /**
     * Publish all unpublished rooms
     */
    public function publishAllRooms(): JsonResponse
    {
        try {
            $unpublishedRooms = Room::where('is_published', false)
                ->where('pending_deletion', false)
                ->get();

            $publishedCount = 0;
            foreach ($unpublishedRooms as $room) {
                $publishedData = $room->only([
                    'name', 'description', 'panorama_image_path', 'thumbnail_path', 'building_id'
                ]);
                
                $room->update([
                    'is_published' => true,
                    'published_data' => $publishedData
                ]);
                $publishedCount++;
            }

            $this->logActivity('published', 'room', null, 'All Rooms', [
                'total_published' => $publishedCount
            ]);

            return response()->json([
                'message' => "Successfully published {$publishedCount} rooms",
                'total_published' => $publishedCount
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to publish rooms'], 500);
        }
    }

    /**
     * Get unpublished rooms count for status
     */
    public function getUnpublishedRoomsCount(): int
    {
        return Room::where('is_published', false)
            ->where('pending_deletion', false)
            ->count();
    }

    /**
     * Revert a room to its published state or delete if never published
     */
    public function revertRoom($id): JsonResponse
    {
        try {
            $room = Room::findOrFail($id);
            
            if (!$room->published_data) {
                // If no published data exists, this is a newly created room
                // Delete it instead of reverting
                $roomName = $room->name;
                $buildingId = $room->building_id;
                
                $room->delete();

                $this->logActivity('deleted', 'room', $id, $roomName, [
                    'name' => $roomName
                ]);

                return response()->json([
                    'message' => 'New room deleted successfully',
                    'action' => 'deleted'
                ]);
            }
            
            // Store the room name before reverting for logging
            $roomName = $room->name;
            
            // Revert to published state
            $room->fill($room->published_data);
            $room->is_published = true; // Keep it published since we're reverting to published state
            $room->save();

            // Log revert action with no details (as requested)
            $this->logActivity('reverted', 'room', $room->id, $roomName, null);

            return response()->json([
                'message' => 'Room reverted to published state successfully',
                'action' => 'reverted',
                'room' => $room
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to revert room',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
