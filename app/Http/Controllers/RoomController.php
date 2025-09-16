<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use App\Traits\LogsActivity;

class RoomController extends Controller
{
    use LogsActivity;
    /**
     * Get all rooms for a specific building
     */
    public function getRoomsForBuilding($buildingId): JsonResponse
    {
        try {
            $building = Building::findOrFail($buildingId);
            
            // Get rooms that are currently published OR have been published at least once
            $rooms = $building->rooms()
                ->where(function($query) {
                    $query->where('is_published', true)  // Currently published rooms
                          ->orWhereNotNull('published_data'); // Rooms that have been published at least once
                })
                ->where('pending_deletion', false)
                ->select([
                    'id',
                    'building_id', 
                    'name',
                    'description',
                    'panorama_image_path',
                    'thumbnail_path',
                    'published_data',
                    'is_published',
                    'created_at',
                    'updated_at'
                ])
                ->orderBy('name')
                ->get();

            // Transform each room to use published data instead of current data (defensive)
            $publishedRooms = collect();
            foreach ($rooms as $room) {
                try {
                    $snapshot = $room->published_data;
                    if (is_string($snapshot)) {
                        $decoded = json_decode($snapshot, true);
                        $snapshot = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                    }

                    if (is_array($snapshot) && !empty($snapshot)) {
                        $publishedRooms->push([
                            'id' => $room->id,
                            'building_id' => $room->building_id,
                            'name' => $snapshot['name'] ?? $room->name,
                            'description' => $snapshot['description'] ?? $room->description,
                            'panorama_image_path' => $snapshot['panorama_image_path'] ?? $room->panorama_image_path,
                            'thumbnail_path' => $snapshot['thumbnail_path'] ?? $room->thumbnail_path,
                            'created_at' => $room->created_at,
                            'updated_at' => $room->updated_at,
                        ]);
                        continue;
                    }

                    if ($room->is_published) {
                        $publishedRooms->push([
                            'id' => $room->id,
                            'building_id' => $room->building_id,
                            'name' => $room->name,
                            'description' => $room->description,
                            'panorama_image_path' => $room->panorama_image_path,
                            'thumbnail_path' => $room->thumbnail_path,
                            'created_at' => $room->created_at,
                            'updated_at' => $room->updated_at,
                        ]);
                    }
                } catch (\Throwable $ex) {
                    \Log::warning('Rooms transform error', [
                        'room_id' => $room->id ?? null,
                        'error' => $ex->getMessage(),
                    ]);
                    // Skip problematic record instead of 500
                }
            }

            return response()->json($publishedRooms->values(), 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch rooms'], 500);
        }
    }

    /**
     * Get all rooms for a building (admin - includes unpublished)
     */
    public function getAdminRoomsForBuilding($buildingId): JsonResponse
    {
        try {
            $building = Building::findOrFail($buildingId);
            
            $rooms = $building->rooms()
                ->where('pending_deletion', false)
                ->orderBy('name')
                ->get();

            return response()->json($rooms);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch rooms'], 500);
        }
    }

    /**
     * Get a specific room
     */
    public function show($id): JsonResponse
    {
        try {
            $room = Room::where(function($query) {
                    $query->where('is_published', true)
                          ->orWhereNotNull('published_data');
                })
                ->where('pending_deletion', false)
                ->findOrFail($id);

            // Use published_data snapshot if available
            if ($room->published_data && is_array($room->published_data)) {
                $roomData = [
                    'id' => $room->id,
                    'building_id' => $room->building_id,
                    'name' => $room->published_data['name'] ?? $room->name,
                    'description' => $room->published_data['description'] ?? $room->description,
                    'panorama_image_path' => $room->published_data['panorama_image_path'] ?? $room->panorama_image_path,
                    'thumbnail_path' => $room->published_data['thumbnail_path'] ?? $room->thumbnail_path,
                    'created_at' => $room->created_at,
                    'updated_at' => $room->updated_at,
                ];
                return response()->json($roomData);
            }

            return response()->json($room);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Room not found'], 404);
        }
    }

    /**
     * Store a new room
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'building_id' => 'required|exists:buildings,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'panorama_image' => 'nullable|image|mimes:jpeg,jpg,png|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $roomData = [
                'building_id' => $request->building_id,
                'name' => $request->name,
                'description' => $request->description,
                'is_published' => false, // Rooms start unpublished
                'pending_deletion' => false
            ];

            // Handle panorama image upload
            if ($request->hasFile('panorama_image')) {
                $panoramaPath = $this->handleImageUpload(
                    $request->file('panorama_image'), 
                    'rooms/360',
                    'panorama'
                );
                $roomData['panorama_image_path'] = $panoramaPath;

                // Generate thumbnail
                $thumbnailPath = $this->generateThumbnail($panoramaPath, 'rooms/thumbnails');
                $roomData['thumbnail_path'] = $thumbnailPath;
            }

            $room = Room::create($roomData);

            // Activity: created (room) with name + image only
            $this->logActivity(
                'created',
                'room',
                $room->id,
                $room->name,
                [
                    'name' => $room->name,
                    'image' => $room->panorama_image_path
                ]
            );

            return response()->json([
                'message' => 'Room created successfully',
                'room' => $room
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create room'], 500);
        }
    }

    /**
     * Update a room
     */
    public function update(Request $request, $id): JsonResponse
    {
        // Debug: Log what we're receiving
        \Log::info('Room update request data:', $request->all());
        \Log::info('Room update request files:', $request->files->all());
        \Log::info('Raw request content length: ' . strlen($request->getContent()));
        \Log::info('Request headers:', $request->headers->all());
        \Log::info('$_POST data:', $_POST);
        \Log::info('$_FILES data:', $_FILES);
        
        // Get the raw request body
        $rawBody = $request->getContent();
        \Log::info('Raw body first 500 chars: ' . substr($rawBody, 0, 500));
        
        // Parse multipart data manually
        $boundary = null;
        $contentType = $request->header('Content-Type');
        if (preg_match('/boundary=(.+)$/', $contentType, $matches)) {
            $boundary = $matches[1];
        }
        
        \Log::info('Boundary: ' . ($boundary ?? 'not found'));
        
        $data = [];
        if ($boundary && $rawBody) {
            // Split by boundary
            $parts = explode('--' . $boundary, $rawBody);
            \Log::info('Number of parts: ' . count($parts));
            
            foreach ($parts as $part) {
                if (empty(trim($part)) || trim($part) === '--') continue;
                
                // Split headers and content
                $sections = explode("\r\n\r\n", $part, 2);
                if (count($sections) !== 2) continue;
                
                $headers = $sections[0];
                $content = rtrim($sections[1], "\r\n");
                
                // Extract field name
                if (preg_match('/name="([^"]+)"/', $headers, $matches)) {
                    $fieldName = $matches[1];
                    $data[$fieldName] = $content;
                    \Log::info("Found field: $fieldName = " . substr($content, 0, 100));
                }
            }
        }
        
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            \Log::error('Room update validation failed:', $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $room = Room::findOrFail($id);
            
            // If room is currently published, save current state as published_data snapshot
            // IMPORTANT: Save this BEFORE any image processing to preserve old images
            if ($room->is_published) {
                $publishedSnapshot = [
                    'name' => $room->name,
                    'description' => $room->description,
                    'panorama_image_path' => $room->panorama_image_path,
                    'thumbnail_path' => $room->thumbnail_path,
                ];
            } else {
                // If already unpublished, keep existing published_data
                $publishedSnapshot = $room->published_data;
            }
            
            $roomData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_published' => false, // Mark as unpublished when updated
                'published_data' => $publishedSnapshot, // Save current published state
            ];

            // Handle new panorama image upload
            $panoramaFile = null;
            
            // First try $_FILES (standard approach)
            if (isset($_FILES['panorama_image']) && $_FILES['panorama_image']['error'] === UPLOAD_ERR_OK) {
                $panoramaFile = new \Illuminate\Http\UploadedFile(
                    $_FILES['panorama_image']['tmp_name'],
                    $_FILES['panorama_image']['name'],
                    $_FILES['panorama_image']['type'],
                    $_FILES['panorama_image']['error']
                );
                \Log::info('File uploaded successfully via $_FILES');
            }
            // If that fails, check if we have file data in our manually parsed data
            elseif (isset($data['panorama_image']) && strlen($data['panorama_image']) > 1000) {
                // This is likely binary file data, save it as a temporary file
                $tempFile = tempnam(sys_get_temp_dir(), 'room_upload_');
                file_put_contents($tempFile, $data['panorama_image']);
                
                // Create an UploadedFile from the temp file
                $panoramaFile = new \Illuminate\Http\UploadedFile(
                    $tempFile,
                    'uploaded_image.jpg', // Default filename
                    'image/jpeg', // Default mime type
                    null,
                    true // Mark as test file to avoid validation issues
                );
                \Log::info('File created from manually parsed data, size: ' . strlen($data['panorama_image']));
            } else {
                \Log::info('No file uploaded');
            }
            
            if ($panoramaFile && $panoramaFile instanceof \Illuminate\Http\UploadedFile) {
                // IMPORTANT: Do NOT delete old images here. They are needed for published_data snapshot and revert.
                // Old images should only be deleted when the room is permanently deleted, or when a new image
                // is published (at which point the *previous* published image can be safely removed).
                // if ($room->panorama_image_path) {
                //     Storage::disk('public')->delete($room->panorama_image_path);
                // }
                // if ($room->thumbnail_path) {
                //     Storage::disk('public')->delete($room->thumbnail_path);
                // }

                // Upload new images
                $panoramaPath = $this->handleImageUpload(
                    $panoramaFile, 
                    'rooms/360',
                    'panorama'
                );
                $roomData['panorama_image_path'] = $panoramaPath;

                // Generate new thumbnail
                $thumbnailPath = $this->generateThumbnail($panoramaPath, 'rooms/thumbnails');
                $roomData['thumbnail_path'] = $thumbnailPath;
            }

            // Track what actually changed for activity logging
            $oldName = $room->name;
            $oldImage = $room->panorama_image_path;
            $nameChanged = $oldName !== $data['name'];
            $imageChanged = isset($roomData['panorama_image_path']);

            $room->update($roomData);

            // Activity: updated (room) - only log what actually changed
            $activityDetails = [];
            if ($nameChanged) {
                $activityDetails['name'] = $oldName . ' → ' . $room->name;
            }
            if ($imageChanged) {
                $activityDetails['image'] = 'image changed';
            }

            $this->logActivity(
                'updated',
                'room',
                $room->id,
                $room->name,
                $activityDetails
            );

            return response()->json([
                'message' => 'Room updated successfully',
                'room' => $room
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update room'], 500);
        }
    }

    /**
     * Delete a room
     */
    public function destroy($id): JsonResponse
    {
        try {
            $room = Room::findOrFail($id);
            
            // Mark for deletion instead of immediate deletion
            $room->update([
                'pending_deletion' => true,
                'is_published' => false
            ]);

            // Activity: deleted (room) with name + image only
            $this->logActivity(
                'deleted',
                'room',
                $room->id,
                $room->name,
                [
                    'name' => $room->name,
                    'image' => $room->panorama_image_path
                ]
            );

            return response()->json(['message' => 'Room marked for deletion']);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete room'], 500);
        }
    }

    /**
     * Handle image upload with optimization
     */
    private function handleImageUpload($file, $directory, $prefix = 'image'): string
    {
        $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $directory . '/' . $filename;

        // Create directory if it doesn't exist
        Storage::disk('public')->makeDirectory($directory);

        // Check if GD extension is available for image processing
        if (extension_loaded('gd')) {
            try {
                // For panorama images, we want to maintain quality but optimize size
                $manager = new ImageManager(new Driver());
                $image = $manager->read($file);
                
                // Resize if too large (max 4K width for 360° images)
                if ($image->width() > 4096) {
                    $image->scaleDown(width: 4096);
                }

                // Save with good quality
                $image->toJpeg(85)->save(storage_path('app/public/' . $path));
            } catch (\Exception $e) {
                // If image processing fails, just store the original file
                \Log::warning('Image processing failed, storing original file: ' . $e->getMessage());
                $file->storeAs($directory, $filename, 'public');
            }
        } else {
            // If GD is not available, just store the original file
            \Log::info('GD extension not available, storing original file without processing');
            $file->storeAs($directory, $filename, 'public');
        }

        return $path;
    }

    /**
     * Generate thumbnail from panorama image
     */
    private function generateThumbnail($panoramaPath, $thumbnailDirectory): string
    {
        $filename = 'thumb_' . time() . '_' . uniqid() . '.jpg';
        $thumbnailPath = $thumbnailDirectory . '/' . $filename;

        // Create directory if it doesn't exist
        Storage::disk('public')->makeDirectory($thumbnailDirectory);

        // Check if GD extension is available for thumbnail generation
        if (extension_loaded('gd')) {
            try {
                // Create thumbnail
                $manager = new ImageManager(new Driver());
                $image = $manager->read(storage_path('app/public/' . $panoramaPath));
                $image->cover(300, 200);
                $image->toJpeg(80)->save(storage_path('app/public/' . $thumbnailPath));
            } catch (\Exception $e) {
                // If thumbnail generation fails, copy the original image as thumbnail
                \Log::warning('Thumbnail generation failed, using original image: ' . $e->getMessage());
                copy(storage_path('app/public/' . $panoramaPath), storage_path('app/public/' . $thumbnailPath));
            }
        } else {
            // If GD is not available, copy the original image as thumbnail
            \Log::info('GD extension not available, using original image as thumbnail');
            copy(storage_path('app/public/' . $panoramaPath), storage_path('app/public/' . $thumbnailPath));
        }

        return $thumbnailPath;
    }
}
