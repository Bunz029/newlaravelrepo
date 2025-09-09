<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faculty extends Model
{
    use HasFactory;

    protected $table = 'faculty';

    protected $fillable = ['faculty_name', 'email', 'faculty_image', 'building_id'];

    // Define the relationship to Building (which is also the department in this case)
    public function building()
    {
        return $this->belongsTo(Building::class, 'building_id');
    }
}
