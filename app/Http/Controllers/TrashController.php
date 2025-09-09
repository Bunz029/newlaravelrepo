<?php

namespace App\Http\Controllers;

use App\Models\DeletedItem;
use App\Models\Building;
use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\LogsActivity;

class TrashController extends Controller
{
    use LogsActivity;
    /**
     * Get all deleted items
     */
    public function index(): JsonResponse
    {
        try {
            $deletedBuildings = DeletedItem::deletedBuildings();
            $deletedMaps = DeletedItem::deletedMaps();

            return response()->json([
                'buildings' => $deletedBuildings,
                'maps' => $deletedMaps,
                'total' => $deletedBuildings->count() + $deletedMaps->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch deleted items',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get deleted buildings only
     */
    public function buildings(): JsonResponse
    {
        try {
            $deletedBuildings = DeletedItem::deletedBuildings();
            return response()->json($deletedBuildings);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch deleted buildings',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get deleted maps only
     */
    public function maps(): JsonResponse
    {
        try {
            $deletedMaps = DeletedItem::deletedMaps();
            return response()->json($deletedMaps);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch deleted maps',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a deleted item
     */
    public function restore($id): JsonResponse
    {
        try {
            $deletedItem = DeletedItem::findOrFail($id);
            
            if ($deletedItem->restore()) {
                // Log restored action with details
                if ($deletedItem->item_type === 'building' && is_array($deletedItem->item_data)) {
                    $data = $deletedItem->item_data;
                    $details = [
                        'building_name' => $data['building_name'] ?? ($data['name'] ?? null),
                        'description' => $data['description'] ?? null,
                        'position' => isset($data['x_coordinate'], $data['y_coordinate']) ? '(' . $data['x_coordinate'] . ', ' . $data['y_coordinate'] . ')' : null,
                        'services' => $data['services'] ?? null,
                        'employees' => isset($data['employees']) && is_array($data['employees']) ? array_map(function ($e) {
                            return is_array($e) ? ($e['employee_name'] ?? $e['name'] ?? $e['email'] ?? 'Employee') : (string) $e;
                        }, $data['employees']) : []
                    ];
                    $this->logActivity('restored', 'building', $deletedItem->original_id, $details['building_name'] ?? 'Building', $details);
                }
                return response()->json([
                    'message' => ucfirst($deletedItem->item_type) . ' restored successfully'
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to restore item'
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to restore item',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permanently delete an item from trash
     */
    public function permanentDelete($id): JsonResponse
    {
        try {
            $deletedItem = DeletedItem::findOrFail($id);
            $itemType = $deletedItem->item_type;
            
            $deletedItem->permanentDelete();
            
            return response()->json([
                'message' => ucfirst($itemType) . ' permanently deleted'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to permanently delete item',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Empty the entire trash
     */
    public function empty(): JsonResponse
    {
        try {
            $count = DeletedItem::count();
            DeletedItem::truncate();
            
            return response()->json([
                'message' => "Trash emptied successfully. {$count} items permanently deleted."
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to empty trash',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Move an item to trash (soft delete)
     */
    public static function moveToTrash($itemType, $item, $deletedBy = null)
    {
        try {
            DeletedItem::create([
                'item_type' => $itemType,
                'original_id' => $item->id,
                'item_data' => $item->toArray(),
                'deleted_by' => $deletedBy,
                'deleted_at' => now()
            ]);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
