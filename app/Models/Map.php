<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Map extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'image_path',
        'width',
        'height',
        'is_active',
        'is_published',
        'published_at',
        'published_by',
        'published_data',
        'pending_deletion',
        'layout_snapshot'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'published_data' => 'array',
        'layout_snapshot' => 'array',
        'pending_deletion' => 'boolean',
        'width' => 'integer',
        'height' => 'integer'
    ];

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($map) {
            // Create trash entry before deleting
            \App\Models\DeletedItem::create([
                'type' => 'map',
                'original_id' => $map->id,
                'data' => $map->toArray(),
                'deleted_at' => now(),
            ]);

            // Delete associated image file
            if ($map->image_path) {
                Storage::disk('public')->delete($map->image_path);
            }
        });
    }
} 