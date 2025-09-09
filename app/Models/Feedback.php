<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    use HasFactory;

    // Define the table name if it differs from the default (optional)
    protected $table = 'feedbacks';

    // Specify the fillable fields
    protected $fillable = [
        'user_email',
        'message',
    ];
}
