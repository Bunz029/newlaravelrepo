<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Building;
use App\Models\Room;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\LogsActivity;

class MapExportController extends Controller
{
    use LogsActivity;

    /**
     * Export a complete map layout with all data
     */
    public function exportMap($id)
    {
        Log::info('MapExportController: Method called with ID: ' . $id);
        
        try {
            Log::info('MapExportController: Starting export for map ID: ' . $id);
            
            $map = Map::with([
                'buildings.employees',
                'buildings.rooms'
            ])->findOrFail($id);
            
            Log::info('MapExportController: Found map: ' . $map->name);

            // Create export data structure
            $exportData = [
                'export_info' => [
                    'version' => '1.0',
                    'exported_at' => now()->toISOString(),
                    'exported_by' => Auth::user()->name ?? 'Admin',
                    'map_id' => $map->id,
                    'map_name' => $map->name
                ],
                'map' => [
                    'name' => $map->name,
                    'width' => $map->width,
                    'height' => $map->height,
                    'is_active' => $map->is_active,
                    'image_path' => $map->image_path,
                    'image_data' => $this->getImageAsBase64($map->image_path)
                ],
                'buildings' => [],
                'rooms' => []
            ];

            // Export buildings with all their data
            foreach ($map->buildings as $building) {
                $buildingData = [
                    'building_name' => $building->building_name,
                    'description' => $building->description,
                    'services' => $building->services,
                    'x_coordinate' => $building->x_coordinate,
                    'y_coordinate' => $building->y_coordinate,
                    'width' => $building->width,
                    'height' => $building->height,
                    'latitude' => $building->latitude,
                    'longitude' => $building->longitude,
                    'image_path' => $building->image_path,
                    'modal_image_path' => $building->modal_image_path,
                    'image_data' => $this->getImageAsBase64($building->image_path),
                    'modal_image_data' => $this->getImageAsBase64($building->modal_image_path),
                    'employees' => [],
                    'rooms' => []
                ];

                // Export employees for this building
                foreach ($building->employees as $employee) {
                    $buildingData['employees'][] = [
                        'name' => $employee->name,
                        'position' => $employee->position,
                        'department' => $employee->department,
                        'email' => $employee->email,
                        'phone' => $employee->phone,
                        'image_path' => $employee->image_path,
                        'image_data' => $this->getImageAsBase64($employee->image_path)
                    ];
                }

                // Export rooms for this building
                foreach ($building->rooms as $room) {
                    $roomData = [
                        'name' => $room->name,
                        'description' => $room->description,
                        'panorama_image_path' => $room->panorama_image_path,
                        'thumbnail_path' => $room->thumbnail_path,
                        'panorama_image_data' => $this->getImageAsBase64($room->panorama_image_path),
                        'thumbnail_data' => $this->getImageAsBase64($room->thumbnail_path)
                    ];
                    $buildingData['rooms'][] = $roomData;
                    $exportData['rooms'][] = $roomData;
                }

                $exportData['buildings'][] = $buildingData;
            }

            // Log the export activity
            $this->logMapActivity('exported', $map, [
                'exported_by' => Auth::user()->name ?? 'Admin',
                'buildings_count' => count($exportData['buildings']),
                'rooms_count' => count($exportData['rooms'])
            ]);

            return response()->json([
                'success' => true,
                'data' => $exportData,
                'filename' => 'map_export_' . $map->name . '_' . now()->format('Y-m-d_H-i-s') . '.json'
            ]);

        } catch (\Exception $e) {
            Log::error('Map export failed', [
                'map_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export map: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import a complete map layout from exported data
     */
    public function importMap(Request $request)
    {
        try {
            $request->validate([
                'map_data' => 'required|array',
                'map_data.map' => 'required|array',
                'map_data.map.name' => 'required|string|max:255',
                'map_data.map.width' => 'required|integer|min:1',
                'map_data.map.height' => 'required|integer|min:1'
            ]);

            $importData = $request->input('map_data');
            $importInfo = $importData['export_info'] ?? [];

            // Create new map
            $map = new Map();
            $map->name = $importData['map']['name'] . ' (Imported)';
            $map->width = $importData['map']['width'];
            $map->height = $importData['map']['height'];
            $map->is_active = false; // Imported maps are inactive by default
            $map->is_published = false;

            // Handle map image
            if (isset($importData['map']['image_data']) && $importData['map']['image_data']) {
                $map->image_path = $this->saveImageFromBase64(
                    $importData['map']['image_data'],
                    'maps',
                    'map_' . time() . '.jpg'
                );
            }

            $map->save();

            // Import buildings
            if (isset($importData['buildings']) && is_array($importData['buildings'])) {
                foreach ($importData['buildings'] as $buildingData) {
                    $building = new Building();
                    $building->map_id = $map->id;
                    $building->building_name = $buildingData['building_name'];
                    $building->description = $buildingData['description'] ?? '';
                    $building->services = $buildingData['services'] ?? [];
                    $building->x_coordinate = $buildingData['x_coordinate'] ?? 0;
                    $building->y_coordinate = $buildingData['y_coordinate'] ?? 0;
                    $building->width = $buildingData['width'] ?? 30;
                    $building->height = $buildingData['height'] ?? 30;
                    $building->latitude = $buildingData['latitude'] ?? null;
                    $building->longitude = $buildingData['longitude'] ?? null;
                    $building->is_published = false;

                    // Handle building images
                    if (isset($buildingData['image_data']) && $buildingData['image_data']) {
                        $building->image_path = $this->saveImageFromBase64(
                            $buildingData['image_data'],
                            'buildings',
                            'building_' . time() . '_' . rand(1000, 9999) . '.jpg'
                        );
                    }

                    if (isset($buildingData['modal_image_data']) && $buildingData['modal_image_data']) {
                        $building->modal_image_path = $this->saveImageFromBase64(
                            $buildingData['modal_image_data'],
                            'buildings',
                            'modal_' . time() . '_' . rand(1000, 9999) . '.jpg'
                        );
                    }

                    $building->save();

                    // Import employees for this building
                    if (isset($buildingData['employees']) && is_array($buildingData['employees'])) {
                        foreach ($buildingData['employees'] as $employeeData) {
                            $employee = new Employee();
                            $employee->building_id = $building->id;
                            $employee->name = $employeeData['name'];
                            $employee->position = $employeeData['position'] ?? '';
                            $employee->department = $employeeData['department'] ?? '';
                            $employee->email = $employeeData['email'] ?? '';
                            $employee->phone = $employeeData['phone'] ?? '';
                            $employee->is_published = false;

                            // Handle employee image
                            if (isset($employeeData['image_data']) && $employeeData['image_data']) {
                                $employee->image_path = $this->saveImageFromBase64(
                                    $employeeData['image_data'],
                                    'employees',
                                    'employee_' . time() . '_' . rand(1000, 9999) . '.jpg'
                                );
                            }

                            $employee->save();
                        }
                    }

                    // Import rooms for this building
                    if (isset($buildingData['rooms']) && is_array($buildingData['rooms'])) {
                        foreach ($buildingData['rooms'] as $roomData) {
                            $room = new Room();
                            $room->building_id = $building->id;
                            $room->name = $roomData['name'];
                            $room->description = $roomData['description'] ?? '';
                            $room->is_published = false;

                            // Handle room images
                            if (isset($roomData['panorama_image_data']) && $roomData['panorama_image_data']) {
                                $room->panorama_image_path = $this->saveImageFromBase64(
                                    $roomData['panorama_image_data'],
                                    'rooms/360',
                                    'panorama_' . time() . '_' . rand(1000, 9999) . '.jpg'
                                );
                            }

                            if (isset($roomData['thumbnail_data']) && $roomData['thumbnail_data']) {
                                $room->thumbnail_path = $this->saveImageFromBase64(
                                    $roomData['thumbnail_data'],
                                    'rooms/thumbnails',
                                    'thumb_' . time() . '_' . rand(1000, 9999) . '.jpg'
                                );
                            }

                            $room->save();
                        }
                    }
                }
            }

            // Log the import activity
            $this->logMapActivity('imported', $map, [
                'imported_by' => Auth::user()->name ?? 'Admin',
                'original_map_name' => $importData['map']['name'],
                'original_export_date' => $importInfo['exported_at'] ?? 'Unknown',
                'buildings_imported' => count($importData['buildings'] ?? []),
                'rooms_imported' => count($importData['rooms'] ?? [])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Map imported successfully',
                'map' => $map->load(['buildings.employees', 'buildings.rooms'])
            ]);

        } catch (\Exception $e) {
            Log::error('Map import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to import map: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get image as base64 string
     */
    private function getImageAsBase64($imagePath)
    {
        if (!$imagePath) {
            return null;
        }

        try {
            $fullPath = storage_path('app/public/' . $imagePath);
            if (file_exists($fullPath)) {
                $imageData = file_get_contents($fullPath);
                $mimeType = mime_content_type($fullPath);
                return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to encode image as base64', [
                'image_path' => $imagePath,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Save base64 image data to storage
     */
    private function saveImageFromBase64($base64Data, $directory, $filename)
    {
        try {
            // Extract base64 data
            if (strpos($base64Data, 'data:') === 0) {
                $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            }

            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                throw new \Exception('Invalid base64 data');
            }

            // Ensure directory exists
            $fullDirectory = storage_path('app/public/' . $directory);
            if (!file_exists($fullDirectory)) {
                mkdir($fullDirectory, 0755, true);
            }

            // Save file
            $fullPath = $fullDirectory . '/' . $filename;
            file_put_contents($fullPath, $imageData);

            return $directory . '/' . $filename;

        } catch (\Exception $e) {
            Log::error('Failed to save base64 image', [
                'directory' => $directory,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Log map activity
     */
    private function logMapActivity($action, $map, $details = [])
    {
        try {
            $this->logActivity(
                $action,
                'map',
                $map->id,
                $map->name,
                $details
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log map activity', [
                'action' => $action,
                'map_id' => $map->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
