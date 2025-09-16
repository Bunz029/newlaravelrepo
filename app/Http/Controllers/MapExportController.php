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
use ZipArchive;

class MapExportController extends Controller
{
    use LogsActivity;

    /**
     * Export a complete map layout with all data as ZIP file
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

            // Create temporary directory for export
            $tempDir = storage_path('app/temp/export_' . time());
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Create images directory
            $imagesDir = $tempDir . '/images';
            mkdir($imagesDir, 0755, true);

            // Create export data structure
            $exportData = [
                'export_info' => [
                    'version' => '2.0',
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
                    'image_filename' => $this->copyImageToExport($map->image_path, $imagesDir, 'map')
                ],
                'buildings' => [],
                'rooms' => []
            ];

            // Export buildings with all their data
            Log::info('MapExportController: Exporting buildings', [
                'buildings_count' => $map->buildings->count()
            ]);
            
            foreach ($map->buildings as $buildingIndex => $building) {
                Log::info("MapExportController: Processing building {$buildingIndex}", [
                    'building_name' => $building->building_name,
                    'employees_count' => $building->employees->count(),
                    'rooms_count' => $building->rooms->count()
                ]);

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
                    'image_filename' => $this->copyImageToExport($building->image_path, $imagesDir, 'building'),
                    'modal_image_filename' => $this->copyImageToExport($building->modal_image_path, $imagesDir, 'modal'),
                    'employees' => [],
                    'rooms' => []
                ];

                // Export employees for this building
                foreach ($building->employees as $employeeIndex => $employee) {
                    Log::info("MapExportController: Processing employee {$employeeIndex}", [
                        'name' => $employee->employee_name,
                        'has_image' => !empty($employee->employee_image)
                    ]);
                    
                    $buildingData['employees'][] = [
                        'name' => $employee->employee_name,
                        'position' => $employee->position ?? '',
                        'department' => $employee->department ?? '',
                        'email' => $employee->email ?? '',
                        'image_path' => $employee->employee_image,
                        'image_filename' => $this->copyImageToExport($employee->employee_image, $imagesDir, 'employee')
                    ];
                }

                // Export rooms for this building
                foreach ($building->rooms as $roomIndex => $room) {
                    Log::info("MapExportController: Processing room {$roomIndex}", [
                        'name' => $room->name,
                        'has_panorama' => !empty($room->panorama_image_path),
                        'has_thumbnail' => !empty($room->thumbnail_path)
                    ]);
                    
                    $roomData = [
                        'name' => $room->name,
                        'panorama_image_path' => $room->panorama_image_path,
                        'thumbnail_path' => $room->thumbnail_path,
                        'panorama_image_filename' => $this->copyImageToExport($room->panorama_image_path, $imagesDir, 'panorama'),
                        'thumbnail_filename' => $this->copyImageToExport($room->thumbnail_path, $imagesDir, 'thumbnail')
                    ];
                    $buildingData['rooms'][] = $roomData;
                    $exportData['rooms'][] = $roomData;
                }

                $exportData['buildings'][] = $buildingData;
            }

            // Save layout.json
            file_put_contents($tempDir . '/layout.json', json_encode($exportData, JSON_PRETTY_PRINT));

            // Create ZIP file
            $zipFilename = 'map_export_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $map->name) . '_' . now()->format('Y-m-d_H-i-s') . '.zip';
            $zipPath = storage_path('app/temp/' . $zipFilename);
            
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
                throw new \Exception('Cannot create ZIP file');
            }

            // Add files to ZIP
            $this->addDirectoryToZip($zip, $tempDir, '');
            $zip->close();

            // Log the export activity
            $this->logMapActivity('exported', $map, [
                'exported_by' => Auth::user()->name ?? 'Admin',
                'buildings_count' => count($exportData['buildings']),
                'rooms_count' => count($exportData['rooms']),
                'export_type' => 'ZIP'
            ]);

            // Clean up temp directory
            $this->deleteDirectory($tempDir);

            // Return ZIP file as download
            return response()->download($zipPath, $zipFilename)->deleteFileAfterSend(true);

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
     * Import a complete map layout from ZIP file
     */
    public function importMap(Request $request)
    {
        try {
            Log::info('MapImportController: Starting import process');
            
            $request->validate([
                'map_file' => 'required|file|mimes:zip|max:100000' // 100MB max
            ]);

            $zipFile = $request->file('map_file');
            Log::info('MapImportController: ZIP file received', [
                'filename' => $zipFile->getClientOriginalName(),
                'size' => $zipFile->getSize(),
                'mime_type' => $zipFile->getMimeType()
            ]);

            $tempDir = storage_path('app/temp/import_' . time());
            
            // Create temp directory
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Extract ZIP file
            $zip = new ZipArchive();
            $result = $zip->open($zipFile->getPathname());
            if ($result !== TRUE) {
                throw new \Exception('Cannot open ZIP file. Error code: ' . $result);
            }

            Log::info('MapImportController: Extracting ZIP file to: ' . $tempDir);
            $extractResult = $zip->extractTo($tempDir);
            $zip->close();

            if (!$extractResult) {
                throw new \Exception('Failed to extract ZIP file');
            }

            // List extracted files for debugging
            $extractedFiles = $this->listDirectoryRecursive($tempDir);
            Log::info('MapImportController: Extracted files', ['files' => $extractedFiles]);

            // Read layout.json
            $layoutPath = $tempDir . '/layout.json';
            if (!file_exists($layoutPath)) {
                throw new \Exception('layout.json not found in ZIP file. Available files: ' . implode(', ', $extractedFiles));
            }

            $jsonContent = file_get_contents($layoutPath);
            if (!$jsonContent) {
                throw new \Exception('Cannot read layout.json file');
            }

            Log::info('MapImportController: JSON content length: ' . strlen($jsonContent));
            
            $importData = json_decode($jsonContent, true);
            if (!$importData) {
                $jsonError = json_last_error_msg();
                throw new \Exception('Invalid layout.json file. JSON error: ' . $jsonError);
            }

            Log::info('MapImportController: Successfully parsed JSON', [
                'has_map' => isset($importData['map']),
                'buildings_count' => count($importData['buildings'] ?? []),
                'rooms_count' => count($importData['rooms'] ?? [])
            ]);

            $importInfo = $importData['export_info'] ?? [];

            // Generate unique import ID to avoid conflicts
            $importId = time() . '_' . rand(10000, 99999);

            // Create new map with unique name to avoid conflicts
            $map = new Map();
            $map->name = $this->generateUniqueMapName($importData['map']['name']);
            $map->width = $importData['map']['width'];
            $map->height = $importData['map']['height'];
            $map->is_active = false; // Imported maps are inactive by default
            $map->is_published = false;

            // Handle map image with conflict resolution
            if (isset($importData['map']['image_filename']) && $importData['map']['image_filename']) {
                $map->image_path = $this->copyImageFromImportWithConflictResolution(
                    $tempDir . '/images/' . $importData['map']['image_filename'],
                    'maps',
                    'map_' . $importId
                );
            }

            $map->save();

            // Import buildings
            if (isset($importData['buildings']) && is_array($importData['buildings'])) {
                Log::info('MapImportController: Starting building import', [
                    'buildings_count' => count($importData['buildings'])
                ]);
                
                foreach ($importData['buildings'] as $index => $buildingData) {
                    Log::info("MapImportController: Processing building {$index}", [
                        'building_name' => $buildingData['building_name'] ?? 'Unknown',
                        'has_employees' => isset($buildingData['employees']) && is_array($buildingData['employees']),
                        'employees_count' => count($buildingData['employees'] ?? []),
                        'has_rooms' => isset($buildingData['rooms']) && is_array($buildingData['rooms']),
                        'rooms_count' => count($buildingData['rooms'] ?? [])
                    ]);
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

                    // Handle building images with conflict resolution
                    if (isset($buildingData['image_filename']) && $buildingData['image_filename']) {
                        $building->image_path = $this->copyImageFromImportWithConflictResolution(
                            $tempDir . '/images/' . $buildingData['image_filename'],
                            'buildings',
                            'building_' . $importId . '_' . rand(1000, 9999)
                        );
                    }

                    if (isset($buildingData['modal_image_filename']) && $buildingData['modal_image_filename']) {
                        $building->modal_image_path = $this->copyImageFromImportWithConflictResolution(
                            $tempDir . '/images/' . $buildingData['modal_image_filename'],
                            'buildings',
                            'modal_' . $importId . '_' . rand(1000, 9999)
                        );
                    }

                    $building->save();

                    // Import employees for this building
                    if (isset($buildingData['employees']) && is_array($buildingData['employees'])) {
                        Log::info("MapImportController: Importing employees for building {$building->building_name}", [
                            'employees_count' => count($buildingData['employees'])
                        ]);
                        
                        foreach ($buildingData['employees'] as $empIndex => $employeeData) {
                            // Skip if employee name is empty or null
                            if (empty($employeeData['name'])) {
                                Log::warning("MapImportController: Skipping employee {$empIndex} - empty name");
                                continue;
                            }

                            Log::info("MapImportController: Processing employee {$empIndex}", [
                                'name' => $employeeData['name'],
                                'has_image' => isset($employeeData['image_filename']) && $employeeData['image_filename']
                            ]);

                            $employee = new Employee();
                            $employee->building_id = $building->id;
                            $employee->employee_name = $employeeData['name'];
                            $employee->position = $employeeData['position'] ?? '';
                            $employee->department = $employeeData['department'] ?? '';
                            $employee->email = $employeeData['email'] ?? '';
                            $employee->is_published = false;

                            // Handle employee image with conflict resolution
                            if (isset($employeeData['image_filename']) && $employeeData['image_filename']) {
                                $imagePath = $this->copyImageFromImportWithConflictResolution(
                                    $tempDir . '/images/' . $employeeData['image_filename'],
                                    'employees',
                                    'employee_' . $importId . '_' . rand(1000, 9999)
                                );
                                $employee->employee_image = $imagePath;
                                Log::info("MapImportController: Employee image copied", ['path' => $imagePath]);
                            }

                            $employee->save();
                            Log::info("MapImportController: Employee saved with ID: {$employee->id}");
                        }
                    }

                    // Import rooms for this building
                    if (isset($buildingData['rooms']) && is_array($buildingData['rooms'])) {
                        Log::info("MapImportController: Importing rooms for building {$building->building_name}", [
                            'rooms_count' => count($buildingData['rooms'])
                        ]);
                        
                        foreach ($buildingData['rooms'] as $roomIndex => $roomData) {
                            Log::info("MapImportController: Processing room {$roomIndex}", [
                                'name' => $roomData['name'],
                                'has_panorama' => isset($roomData['panorama_image_filename']) && $roomData['panorama_image_filename'],
                                'has_thumbnail' => isset($roomData['thumbnail_filename']) && $roomData['thumbnail_filename']
                            ]);

                            $room = new Room();
                            $room->building_id = $building->id;
                            $room->name = $roomData['name'];
                            $room->is_published = false;

                            // Handle room images with conflict resolution
                            if (isset($roomData['panorama_image_filename']) && $roomData['panorama_image_filename']) {
                                $panoramaPath = $this->copyImageFromImportWithConflictResolution(
                                    $tempDir . '/images/' . $roomData['panorama_image_filename'],
                                    'rooms/360',
                                    'panorama_' . $importId . '_' . rand(1000, 9999)
                                );
                                $room->panorama_image_path = $panoramaPath;
                                Log::info("MapImportController: Room panorama image copied", ['path' => $panoramaPath]);
                            }

                            if (isset($roomData['thumbnail_filename']) && $roomData['thumbnail_filename']) {
                                $thumbnailPath = $this->copyImageFromImportWithConflictResolution(
                                    $tempDir . '/images/' . $roomData['thumbnail_filename'],
                                    'rooms/thumbnails',
                                    'thumb_' . $importId . '_' . rand(1000, 9999)
                                );
                                $room->thumbnail_path = $thumbnailPath;
                                Log::info("MapImportController: Room thumbnail image copied", ['path' => $thumbnailPath]);
                            }

                            $room->save();
                            Log::info("MapImportController: Room saved with ID: {$room->id}");
                        }
                    }
                }
            }

            // Clean up temp directory
            $this->deleteDirectory($tempDir);

            // Log the import activity
            $this->logMapActivity('imported', $map, [
                'imported_by' => Auth::user()->name ?? 'Admin',
                'original_map_name' => $importData['map']['name'],
                'original_export_date' => $importInfo['exported_at'] ?? 'Unknown',
                'buildings_imported' => count($importData['buildings'] ?? []),
                'rooms_imported' => count($importData['rooms'] ?? []),
                'import_type' => 'ZIP'
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
     * Copy image to export directory
     */
    private function copyImageToExport($imagePath, $exportDir, $prefix)
    {
        if (!$imagePath) {
            Log::info("MapExportController: Skipping image copy - no path provided for prefix: {$prefix}");
            return null;
        }

        try {
            $sourcePath = storage_path('app/public/' . $imagePath);
            Log::info("MapExportController: Attempting to copy image", [
                'source_path' => $sourcePath,
                'exists' => file_exists($sourcePath),
                'prefix' => $prefix
            ]);
            
            if (file_exists($sourcePath)) {
                $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
                $filename = $prefix . '_' . time() . '_' . rand(1000, 9999) . '.' . $extension;
                $destPath = $exportDir . '/' . $filename;
                
                $copyResult = copy($sourcePath, $destPath);
                if ($copyResult) {
                    Log::info("MapExportController: Image copied successfully", [
                        'source' => $sourcePath,
                        'dest' => $destPath,
                        'filename' => $filename
                    ]);
                    return $filename;
                } else {
                    Log::error("MapExportController: Failed to copy image", [
                        'source' => $sourcePath,
                        'dest' => $destPath
                    ]);
                }
            } else {
                Log::warning("MapExportController: Source image file not found", [
                    'source_path' => $sourcePath,
                    'image_path' => $imagePath
                ]);
            }
        } catch (\Exception $e) {
            Log::error('MapExportController: Exception during image copy', [
                'image_path' => $imagePath,
                'prefix' => $prefix,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return null;
    }

    /**
     * Generate unique map name to avoid conflicts
     */
    private function generateUniqueMapName($originalName)
    {
        $baseName = $originalName . ' (Imported)';
        $counter = 1;
        $uniqueName = $baseName;

        while (Map::where('name', $uniqueName)->exists()) {
            $uniqueName = $baseName . ' ' . $counter;
            $counter++;
        }

        return $uniqueName;
    }

    /**
     * Copy image from import to storage with conflict resolution
     */
    private function copyImageFromImportWithConflictResolution($sourcePath, $directory, $baseFilename)
    {
        if (!file_exists($sourcePath)) {
            Log::warning("MapImportController: Source image not found", [
                'source_path' => $sourcePath,
                'directory' => $directory,
                'base_filename' => $baseFilename
            ]);
            return null;
        }

        try {
            // Ensure directory exists
            $fullDirectory = storage_path('app/public/' . $directory);
            if (!file_exists($fullDirectory)) {
                mkdir($fullDirectory, 0755, true);
                Log::info("MapImportController: Created directory", ['directory' => $fullDirectory]);
            }

            // Get file extension from source
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            if (!$extension) {
                $extension = 'jpg'; // Default extension
                Log::info("MapImportController: Using default extension", ['extension' => $extension]);
            }

            // Generate unique filename
            $filename = $this->generateUniqueFilename($fullDirectory, $baseFilename, $extension);
            $destPath = $fullDirectory . '/' . $filename;

            Log::info("MapImportController: Copying image", [
                'source' => $sourcePath,
                'dest' => $destPath,
                'filename' => $filename
            ]);

            // Copy file
            $copyResult = copy($sourcePath, $destPath);
            if ($copyResult) {
                Log::info("MapImportController: Image copied successfully", [
                    'source' => $sourcePath,
                    'dest' => $destPath,
                    'final_path' => $directory . '/' . $filename
                ]);
                return $directory . '/' . $filename;
            } else {
                Log::error("MapImportController: Failed to copy image", [
                    'source' => $sourcePath,
                    'dest' => $destPath
                ]);
                return null;
            }

        } catch (\Exception $e) {
            Log::error('MapImportController: Exception during image copy with conflict resolution', [
                'source_path' => $sourcePath,
                'directory' => $directory,
                'base_filename' => $baseFilename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Generate unique filename to avoid conflicts
     */
    private function generateUniqueFilename($directory, $baseFilename, $extension)
    {
        $filename = $baseFilename . '.' . $extension;
        $counter = 1;

        while (file_exists($directory . '/' . $filename)) {
            $filename = $baseFilename . '_' . $counter . '.' . $extension;
            $counter++;
        }

        return $filename;
    }


    /**
     * Copy image from import to storage (legacy method for backward compatibility)
     */
    private function copyImageFromImport($sourcePath, $directory, $filename)
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        try {
            // Ensure directory exists
            $fullDirectory = storage_path('app/public/' . $directory);
            if (!file_exists($fullDirectory)) {
                mkdir($fullDirectory, 0755, true);
            }

            // Copy file
            $destPath = $fullDirectory . '/' . $filename;
            copy($sourcePath, $destPath);

            return $directory . '/' . $filename;

        } catch (\Exception $e) {
            Log::error('Failed to copy image from import', [
                'source_path' => $sourcePath,
                'directory' => $directory,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Add directory to ZIP recursively
     */
    private function addDirectoryToZip($zip, $dir, $zipPath)
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $filePath = $dir . '/' . $file;
            $zipFilePath = $zipPath . $file;
            
            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipFilePath);
                $this->addDirectoryToZip($zip, $filePath, $zipFilePath . '/');
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }

    /**
     * Delete directory recursively
     */
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
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
     * List directory contents recursively
     */
    private function listDirectoryRecursive($dir, $prefix = '')
    {
        $files = [];
        if (is_dir($dir)) {
            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item != '.' && $item != '..') {
                    $path = $dir . '/' . $item;
                    $files[] = $prefix . $item;
                    if (is_dir($path)) {
                        $files = array_merge($files, $this->listDirectoryRecursive($path, $prefix . $item . '/'));
                    }
                }
            }
        }
        return $files;
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
