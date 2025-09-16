<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Traits\LogsActivity;

class MapController extends Controller
{
    use LogsActivity;
    public function index()
    {
        // Check if pending_deletion column exists, if not, get all maps
        $maps = Schema::hasColumn('maps', 'pending_deletion') 
            ? Map::where('pending_deletion', false)->with('buildings')->get()
            : Map::with('buildings')->get();
        
        Log::info('Maps being returned:', $maps->toArray());
        
        // Add image_url to each map
        $maps->each(function ($map) {
            if ($map->image_path) {
                $map->image_url = asset('storage/' . $map->image_path);
                Log::info('Map image URLs:', [
                    'id' => $map->id,
                    'path' => $map->image_path,
                    'url' => $map->image_url
                ]);
            } else {
                $map->image_url = null;
                Log::info('Map has no image path:', ['id' => $map->id]);
            }
        });
        
        return $maps;
    }

    public function store(Request $request)
    {
        Log::info('Map store request received', [
            'has_file' => $request->hasFile('image'),
            'file_size' => $request->hasFile('image') ? $request->file('image')->getSize() : null,
            'file_mime' => $request->hasFile('image') ? $request->file('image')->getMimeType() : null,
            'request_data' => $request->except(['image'])
        ]);

        $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|max:102400', // Increased to 100MB to match PHP configuration
            'width' => 'required|numeric',
            'height' => 'required|numeric',
            'is_published' => 'nullable|in:true,false,1,0'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            try {
                $imagePath = $request->file('image')->store('maps', 'public');
                Log::info('Image stored successfully', ['path' => $imagePath]);
            } catch (\Exception $e) {
                Log::error('Image storage failed', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return response()->json([
                    'message' => 'The image failed to upload.',
                    'errors' => ['image' => [$e->getMessage()]]
                ], 422);
            }
        }

        $map = Map::create([
            'name' => $request->name,
            'image_path' => $imagePath,
            'width' => $request->width,
            'height' => $request->height,
            'is_active' => false, // New maps are inactive by default
            'is_published' => filter_var($request->input('is_published', false), FILTER_VALIDATE_BOOLEAN) // Default to unpublished
        ]);

        $map->image_url = $imagePath ? asset('storage/' . $map->image_path) : null;
        
        // Log the map creation activity
        // Temporarily disabled due to MySQL strict mode issues
        /*
        try {
            $this->logMapActivity('created', $map, [
                'width' => $map->width,
                'height' => $map->height,
                'is_active' => $map->is_active,
                'is_published' => $map->is_published
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log map creation activity', [
                'map_id' => $map->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't rethrow the exception - just log it and continue
        }
        */
        
        return response()->json($map, 201);
    }

    public function show(Map $map)
    {
        $map->load('buildings');
        $map->image_url = $map->image_path ? asset('storage/' . $map->image_path) : null;
        $etag = 'W/"map-' . md5(($map->published_at ?? $map->updated_at ?? now()).'|'.$map->id) . '"';
        return response()->json($map)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function update(Request $request, Map $map)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'image' => 'nullable|image|max:102400', // Increased to 100MB to match PHP configuration
            'width' => 'sometimes|required|integer',
            'height' => 'sometimes|required|integer',
            'is_active' => 'sometimes|required|boolean',
            'is_published' => 'nullable|in:true,false,1,0',
            'pending_deletion' => 'nullable|boolean'
        ]);

        if ($request->hasFile('image')) {
            // Store new image first
            $imagePath = $request->file('image')->store('maps', 'public');
            $oldImagePath = $map->image_path; // Store old path for later cleanup
            $map->image_path = $imagePath;
            
            // Don't delete old image immediately - let it be cleaned up during publish
            // This prevents the app from breaking when it tries to load the old published image
        }

        $data = $request->except('image');
        
        // Convert string boolean to actual boolean for is_published
        if ($request->has('is_published')) {
            $data['is_published'] = filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN);
            
            // If this map was previously published, preserve the published_at timestamp
            // so it doesn't disappear from the app when edited
            if (!$data['is_published'] && $map->published_at) {
                // Keep the published_at timestamp even when marking as unpublished
                // This ensures the map remains visible in the app
                unset($data['published_at']); // Don't overwrite the existing timestamp
            }
        } else {
            // If is_published is not explicitly set, mark as unpublished to require publishing
            $data['is_published'] = false;
        }
        
        $map->fill($data);
        $map->save();

        // Log the map update activity
        $activityData = [
            'width' => $map->width,
            'height' => $map->height,
            'is_active' => $map->is_active,
            'is_published' => $map->is_published,
            'building_count' => $map->buildings->count()
        ];

        // Add specific logging for restoration
        if ($request->has('pending_deletion') && !$request->input('pending_deletion')) {
            $activityData['restored_by'] = Auth::user()->name ?? 'Admin';
            $this->logMapActivity('restored', $map, $activityData);
        } else {
            $this->logMapActivity('updated', $map, $activityData);
        }

        $map->image_url = $map->image_path ? asset('storage/' . $map->image_path) : null;
        $etag = 'W/"map-' . md5(($map->published_at ?? $map->updated_at ?? now()).'|'.$map->id) . '"';
        return response()->json($map)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function saveLayout(Request $request, Map $map)
    {
        // Snapshot map + all buildings for this map
        $map->load('buildings');
        $snapshot = [
            'map' => [
                'id' => $map->id,
                'name' => $map->name,
                'image_path' => $map->image_path,
                'width' => $map->width,
                'height' => $map->height,
            ],
            'buildings' => $map->buildings->map(function ($b) {
                return [
                    'id' => $b->id,
                    'building_name' => $b->building_name,
                    'description' => $b->description,
                    'services' => $b->services,
                    'x_coordinate' => $b->x_coordinate,
                    'y_coordinate' => $b->y_coordinate,
                    'width' => $b->width,
                    'height' => $b->height,
                    'marker_image' => $b->marker_image ?? $b->image_path,
                    'modal_image' => $b->modal_image_path,
                ];
            })->toArray(),
            'saved_at' => now()->toDateTimeString(),
        ];

        $map->layout_snapshot = $snapshot;
        $map->save();

        $this->logMapActivity('layout_saved', $map, [
            'buildings' => count($snapshot['buildings']),
            'width' => $map->width,
            'height' => $map->height,
        ]);

        return response()->json(['message' => 'Layout snapshot saved', 'snapshot' => $snapshot]);
    }

    public function getLayout(Map $map)
    {
        if ($map->layout_snapshot) {
            return response()->json($map->layout_snapshot);
        }
        // Fallback synthesize
        $map->load('buildings');
        $snapshot = [
            'map' => [
                'id' => $map->id,
                'name' => $map->name,
                'image_path' => $map->image_path,
                'width' => $map->width,
                'height' => $map->height,
            ],
            'buildings' => $map->buildings->map(function ($b) {
                return [
                    'id' => $b->id,
                    'building_name' => $b->building_name,
                    'description' => $b->description,
                    'services' => $b->services,
                    'x_coordinate' => $b->x_coordinate,
                    'y_coordinate' => $b->y_coordinate,
                    'width' => $b->width,
                    'height' => $b->height,
                    'marker_image' => $b->marker_image ?? $b->image_path,
                    'modal_image' => $b->modal_image_path,
                ];
            })->toArray(),
            'saved_at' => null,
        ];
        return response()->json($snapshot);
    }

    public function destroy(Map $map)
    {
        // Only mark as pending deletion. Do NOT create DeletedItem yet.
        $map->pending_deletion = true;
        $map->save();

        // Log the map deletion activity
        $this->logMapActivity('deleted', $map, [
            'deleted_by' => Auth::user()->name ?? 'Admin',
            'is_published' => $map->is_published,
            'building_count' => $map->buildings->count()
        ]);

        return response()->json(['message' => 'Map marked for deletion - will be removed from app after publishing'], 200);
    }

    public function activate(Map $map)
    {
        // Store current active map's published data before changing
        $currentActiveMap = Map::where('is_active', true)->first();
        if ($currentActiveMap && $currentActiveMap->is_published) {
            // Store current state as published snapshot before deactivating
            $currentActiveMap->published_data = $currentActiveMap->only([
                'name', 'image_path', 'width', 'height', 'is_active'
            ]);
            $currentActiveMap->save();
        }
        
        // Deactivate all other maps (but keep them published so app doesn't break)
        Map::where('id', '!=', $map->id)->update(['is_active' => false]);
        
        // Activate the selected map and ensure it has a published snapshot for comparison
        $map->is_active = true;
        
        // If this map doesn't have a published snapshot yet, create one with the old state
        if (!$map->published_data && $map->is_published) {
            // Store the state before activation as the published snapshot
            $map->published_data = $map->only([
                'name', 'image_path', 'width', 'height'
            ]);
            $map->published_data['is_active'] = false; // The published state was inactive
        }
        
        $map->save();

        // Log the map activation activity with detailed information
        $this->logMapActivity('activated', $map, [
            'previous_active_map' => $currentActiveMap ? $currentActiveMap->name : null,
            'activated_by' => Auth::user()->name ?? 'Admin',
            'width' => $map->width,
            'height' => $map->height,
            'is_active' => $map->is_active,
            'building_count' => $map->buildings->count()
        ]);

        $map->image_url = $map->image_path ? asset('storage/' . $map->image_path) : null;
        $etag = 'W/"map-' . md5(($map->published_at ?? $map->updated_at ?? now()).'|'.$map->id) . '"';
        return response()->json($map)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function getActive()
    {
        $map = Map::where('is_active', true)->with('buildings')->first();
        if (!$map) {
            return response()->json(['message' => 'No active map found'], 404);
        }
        $map->image_url = $map->image_path ? asset('storage/' . $map->image_path) : null;
        return response()->json($map);
    }

    public function getPublished()
    {
        // Get all maps that have been published at least once
        $maps = Map::whereNotNull('published_at')->with(['buildings' => function($query) {
            // Include buildings that are published and not pending deletion
            // OR buildings that have published snapshots (even if currently unpublished)
            $query->where(function($q) {
                $q->where('is_published', true)
                  ->where('pending_deletion', false);
            })->orWhereNotNull('published_data');
        }])->get();
        
        // Transform maps to use published snapshots where available
        $publishedMaps = $maps->map(function ($map) {
            if ($map->published_data) {
                // Use published snapshot data
                $publishedMap = new Map($map->published_data);
                $publishedMap->id = $map->id;
                $publishedMap->created_at = $map->created_at;
                $publishedMap->updated_at = $map->updated_at;
                
                // Get published buildings using their snapshots
                $publishedBuildings = $map->buildings->filter(function($building) {
                    // Include buildings that have published data and are not pending deletion
                    return $building->published_data && !$building->pending_deletion;
                })->map(function($building) {
                    if ($building->published_data) {
                        $publishedBuilding = new Building($building->published_data);
                        $publishedBuilding->id = $building->id;
                        $publishedBuilding->created_at = $building->created_at;
                        $publishedBuilding->updated_at = $building->updated_at;
                        return $publishedBuilding;
                    }
                    return $building;
                });
                $publishedMap->setRelation('buildings', $publishedBuildings);
                
                return $publishedMap;
            } else {
                // Legacy published item without snapshot - use current data only if still published
                if ($map->is_published) {
                    $publishedBuildings = $map->buildings->filter(function($building) {
                        return $building->is_published && !$building->pending_deletion;
                    });
                    $map->setRelation('buildings', $publishedBuildings);
                    return $map;
                }
                return null; // Don't include unpublished maps without snapshots
            }
        })->filter(); // Remove null values
        
        // Add image URLs
        $publishedMaps->each(function ($map) {
            if ($map->image_path) {
                $map->image_url = asset('storage/' . $map->image_path);
            } else {
                $map->image_url = null;
            }
        });
        
        return $publishedMaps;
    }

    public function getActivePublished()
    {
        // Find the currently active map
        $currentActiveMap = Map::where('is_active', true)->first();
        
        if (!$currentActiveMap) {
            return response()->json(['message' => 'No active map found'], 404);
        }
        
        // ALWAYS use published data if it exists - this ensures unpublished changes are never shown
        if ($currentActiveMap->published_data) {
            // Use the published snapshot data - this is the key fix
            $activeMap = $currentActiveMap;
        } else {
            // Fall back to legacy published maps - only if the current map is published
            if ($currentActiveMap->is_published && $currentActiveMap->published_at) {
                $activeMap = $currentActiveMap;
            } else {
                return response()->json(['message' => 'No active published map found'], 404);
            }
        }
        
        // Use published snapshot if available, otherwise use current data
        if ($activeMap->published_data) {
            // Create a map instance using published data
            $publishedMap = new Map($activeMap->published_data);
            $publishedMap->id = $activeMap->id;
            $publishedMap->created_at = $activeMap->created_at;
            $publishedMap->updated_at = $activeMap->updated_at;
            
            // Get published buildings for this map from snapshots
            $publishedBuildings = Building::whereNotNull('published_data')
                                        ->where('pending_deletion', false)
                                        ->get()
                                        ->filter(function ($building) use ($activeMap) {
                                            return $building->published_data && 
                                                   isset($building->published_data['map_id']) && 
                                                   $building->published_data['map_id'] == $activeMap->id;
                                        })
                                        ->map(function ($building) {
                                            $publishedBuilding = new Building($building->published_data);
                                            $publishedBuilding->id = $building->id;
                                            $publishedBuilding->created_at = $building->created_at;
                                            $publishedBuilding->updated_at = $building->updated_at;
                                            return $publishedBuilding;
                                        });
        } else {
            // Legacy published map - use current data only if still published
            $publishedMap = $activeMap;
            
            // Get published buildings (exclude pending deletion)
            $publishedBuildings = Building::whereNotNull('published_at')
                                        ->where('pending_deletion', false)
                                        ->where('map_id', $activeMap->id)
                                        ->get()
                                        ->filter(function($building) {
                                            // Include only if published data exists or is still published
                                            return $building->published_data || $building->is_published;
                                        });
        }
        
        $publishedMap->setRelation('buildings', $publishedBuildings);
        $publishedMap->image_url = $publishedMap->image_path ? asset('storage/' . $publishedMap->image_path) : null;
        
        $etag = 'W/"map-' . md5(($publishedMap->published_at ?? $publishedMap->updated_at ?? now()).'|'.$publishedMap->id) . '"';
        return response()->json($publishedMap)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function upload(Request $request)
    {
        try {
            Log::info('Upload request received', ['request' => $request->all()]);
            
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg|max:102400' // 100MB max to match PHP configuration
            ]);

            if (!$request->hasFile('image')) {
                Log::error('No image file in request');
                return response()->json(['message' => 'No image file provided'], 400);
            }

            // Store new image
            $imagePath = $request->file('image')->store('maps', 'public');
            Log::info('Image stored successfully', ['path' => $imagePath]);
            
            return response()->json([
                'message' => 'Image uploaded successfully',
                'path' => $imagePath,
                'url' => asset('storage/' . $imagePath)
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Image upload error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to upload image',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}