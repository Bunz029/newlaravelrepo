<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['department_name', 'building_id']; // Define fillable fields

    // If your 'departments' table has a relationship with 'building', define it here.
    public function building()
    {
        return $this->belongsTo(Building::class); // Assuming each department belongs to a building
    }
}