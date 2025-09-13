<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'name',
        'panorama_image_path',
        'thumbnail_path',
        'is_published',
        'published_data',
        'pending_deletion'
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_data' => 'array',
        'pending_deletion' => 'boolean',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($room) {
            // Delete associated images when room is permanently deleted
            if ($room->panorama_image_path) {
                Storage::disk('public')->delete($room->panorama_image_path);
            }
            
            if ($room->thumbnail_path) {
                Storage::disk('public')->delete($room->thumbnail_path);
            }
        });
    }

    // Get panorama image URL accessor
    public function getPanoramaImageUrlAttribute()
    {
        if (!$this->panorama_image_path) {
            return null;
        }
        
        return asset('storage/' . $this->panorama_image_path);
    }

    // Get thumbnail URL accessor
    public function getThumbnailUrlAttribute()
    {
        if (!$this->thumbnail_path) {
            return null;
        }
        
        return asset('storage/' . $this->thumbnail_path);
    }
}
