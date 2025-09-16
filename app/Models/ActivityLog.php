<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'activity_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'target_type',
        'target_id',
        'target_name',
        'details',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be hidden for arrays.
     */
    protected $hidden = [];

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = true;

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'details' => 'array',
        'user_id' => 'integer',
        'target_id' => 'integer',
    ];
}
