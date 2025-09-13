<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use HasFactory;

    protected $table = 'employees';

    protected $fillable = [
        'employee_name', 
        'contact_number',
        'employee_image', 
        'building_id',
        'is_published',
        'published_at',
        'published_by',
        'published_data'
    ];

    protected $attributes = [
        // Use public/images path (served directly), not storage
        'employee_image' => 'images/employees/default-profile-icon.png'
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_data' => 'array',
        'published_at' => 'datetime'
    ];

    // Define the relationship to Building
    public function building()
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    // Accessor to ensure default image is returned when no image is provided
    public function getEmployeeImageAttribute($value)
    {
        if (empty($value) || $value === null) {
            return 'images/employees/default-profile-icon.png';
        }
        return $value;
    }

    // Accessor to get the full URL for the employee image
    public function getEmployeeImageUrlAttribute()
    {
        // If value is in public/images (not storage), return asset directly
        if ($this->employee_image && str_starts_with($this->employee_image, 'images/')) {
            return asset($this->employee_image);
        }
        if ($this->employee_image) {
            return asset('storage/' . $this->employee_image);
        }
        return asset('images/employees/default-profile-icon.png');
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($employee) {
            // Delete associated image when employee is permanently deleted (but not the default)
            if ($employee->employee_image && $employee->employee_image !== 'images/employees/default-profile-icon.png') {
                Storage::disk('public')->delete($employee->employee_image);
            }
        });
    }
}
