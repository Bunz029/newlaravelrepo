<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DeletedItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_type',
        'original_id',
        'item_data',
        'deleted_by',
        'deleted_at'
    ];

    protected $casts = [
        'item_data' => 'array',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get all deleted buildings
     */
    public static function deletedBuildings()
    {
        return self::where('item_type', 'building')
                  ->orderBy('deleted_at', 'desc')
                  ->get();
    }

    /**
     * Get all deleted maps
     */
    public static function deletedMaps()
    {
        return self::where('item_type', 'map')
                  ->orderBy('deleted_at', 'desc')
                  ->get();
    }

    /**
     * Restore a deleted item
     */
    public function restore()
    {
        if ($this->item_type === 'building') {
            return $this->restoreBuilding();
        } elseif ($this->item_type === 'map') {
            return $this->restoreMap();
        }
        
        return false;
    }

    /**
     * Restore a deleted building
     */
    private function restoreBuilding()
    {
        try {
            $originalId = $this->item_data['id'] ?? null;
            
            if ($originalId) {
                // Try to find the existing building record (it should still exist with pending_deletion = true)
                $building = Building::find($originalId);
                
                if ($building && $building->pending_deletion) {
                    // Simply unmark the pending deletion flag - images should still be intact
                    $building->pending_deletion = false;
                    $building->is_published = false; // Keep as unpublished until manually published
                    $building->save();
                    
                    $this->delete(); // Remove from trash
                    return true;
                }
            }
            
            // Fallback: If building record doesn't exist, recreate it from stored data
            $buildingData = $this->item_data;
            unset($buildingData['id']); // Remove the old ID, let database assign new one
            
            // Ensure image paths are preserved and files exist
            if (isset($buildingData['image_path']) && !Storage::disk('public')->exists($buildingData['image_path'])) {
                $buildingData['image_path'] = null;
            }
            
            if (isset($buildingData['modal_image_path']) && !Storage::disk('public')->exists($buildingData['modal_image_path'])) {
                $buildingData['modal_image_path'] = null;
            }
            
            // Set as unpublished so it doesn't immediately appear in the app
            $buildingData['is_published'] = false;
            $buildingData['pending_deletion'] = false; // Clear pending deletion flag
            
            $restoredBuilding = Building::create($buildingData);
            
            // If this building had published_data, restore it as well
            if (isset($this->item_data['published_data'])) {
                $restoredBuilding->published_data = $this->item_data['published_data'];
                $restoredBuilding->save();
            }
            
            $this->delete(); // Remove from trash
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to restore building: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore a deleted map
     */
    private function restoreMap()
    {
        try {
            $mapData = $this->item_data;
            unset($mapData['id']); // Remove the old ID, let database assign new one
            
            Map::create($mapData);
            $this->delete(); // Remove from trash
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Permanently delete this trash item
     */
    public function permanentDelete()
    {
        try {
            if ($this->item_type === 'building') {
                $buildingId = $this->original_id;
                if ($buildingId) {
                    $building = \App\Models\Building::find($buildingId);
                    if ($building) {
                        $building->delete();
                    }
                }
            } elseif ($this->item_type === 'map') {
                $mapId = $this->original_id;
                if ($mapId) {
                    $map = \App\Models\Map::find($mapId);
                    if ($map) {
                        $map->delete();
                    }
                }
            }

            return $this->delete();
        } catch (\Exception $e) {
            Log::error('Failed to permanently delete original item: ' . $e->getMessage());
            return false;
        }
    }
}
