<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\LogsActivity;

class MapController extends Controller
{
    use LogsActivity;
    public function index()
    {
        $maps = Map::with('buildings')->get();
        
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
        $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|max:20480', // Increased to 20MB for high-res images
            'width' => 'required|numeric',
            'height' => 'required|numeric',
            'is_published' => 'nullable|in:true,false,1,0'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('maps', 'public');
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
        $this->logMapActivity('created', $map, [
            'width' => $map->width,
            'height' => $map->height,
            'is_active' => $map->is_active,
            'is_published' => $map->is_published
        ]);
        
        return response()->json($map, 201);
    }

    public function show(Map $map)
    {
        $map->load('buildings');
        $map->image_url = $map->image_path ? asset('storage/' . $map->image_path) : null;
        return response()->json($map);
    }

    public function update(Request $request, Map $map)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'image' => 'nullable|image|max:20480', // Increased to 20MB for high-res images
            'width' => 'sometimes|required|integer',
            'height' => 'sometimes|required|integer',
            'is_active' => 'sometimes|required|boolean',
            'is_published' => 'nullable|in:true,false,1,0'
        ]);

        if ($request->hasFile('image')) {
            // Delete old image
            if ($map->image_path) {
                Storage::disk('public')->delete($map->image_path);
            }
            $imagePath = $request->file('image')->store('maps', 'public');
            $map->image_path = $imagePath;
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
        $this->logMapActivity('updated', $map, [
            'width' => $map->width,
            'height' => $map->height,
            'is_active' => $map->is_active,
            'is_published' => $map->is_published,
            'building_count' => $map->buildings->count()
        ]);

        $map->image_url = $map->image_path ? asset('storage/' . $map->image_path) : null;
        return response()->json($map);
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
        // Move to trash instead of hard delete
        $moved = \App\Http\Controllers\TrashController::moveToTrash('map', $map, Auth::user()->name ?? 'Admin');
        
        if ($moved) {
            // Only delete the actual record after moving to trash
            $map->delete();
            return response()->json(['message' => 'Map moved to trash successfully'], 200);
        } else {
            return response()->json(['error' => 'Failed to move map to trash'], 500);
        }
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
        return response()->json($map);
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
        // First try to find maps with published snapshots that are marked as active
        $mapsWithSnapshots = Map::whereNotNull('published_data')->get();
        
        $activeMap = null;
        foreach ($mapsWithSnapshots as $map) {
            if ($map->published_data && isset($map->published_data['is_active']) && $map->published_data['is_active']) {
                $activeMap = $map;
                break;
            }
        }
        
        // If no active map found in snapshots, fall back to legacy published maps
        if (!$activeMap) {
            $activeMap = Map::whereNotNull('published_at')
                          ->where('is_published', true)
                          ->where('is_active', true)
                          ->first();
        }
        
        if (!$activeMap) {
            return response()->json(['message' => 'No active published map found'], 404);
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
            if (!$activeMap->is_published) {
                return response()->json(['message' => 'No active published map found'], 404);
            }
            
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
        
        return response()->json($publishedMap);
    }

    public function upload(Request $request)
    {
        try {
            Log::info('Upload request received', ['request' => $request->all()]);
            
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg|max:20480' // 20MB max for high-res images
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