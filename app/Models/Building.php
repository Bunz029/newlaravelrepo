<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Building extends Model
{
    use HasFactory;

    protected $fillable = [
        'map_id',
        'building_name',
        'description',
        'services',
        'x_coordinate',
        'y_coordinate',
        'image_path',
        'modal_image_path',
        'width',
        'height',
        'is_active',
        'is_published',
        'published_data',
        'pending_deletion',
        'latitude',
        'longitude'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'published_data' => 'array',
        'pending_deletion' => 'boolean',
        'x_coordinate' => 'integer',
        'y_coordinate' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float'
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    public function faculty(): HasMany
    {
        return $this->hasMany(Faculty::class, 'building_id');
    }
    
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'building_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'building_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($building) {
            // Do not delete images here; keep them for Trash previews and possible restore.
            // Image cleanup should happen on permanent delete from Trash.
        });
    }

    // Get image URL accessor for compatibility
    public function getImageUrlAttribute()
    {
        if (!$this->image_path) {
            return null;
        }
        
        return asset('storage/' . $this->image_path);
    }

    // Get modal image URL accessor
    public function getModalImageUrlAttribute()
    {
        if (!$this->modal_image_path) {
            return null;
        }
        
        return asset('storage/' . $this->modal_image_path);
    }
}
