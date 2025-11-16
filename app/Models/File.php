<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'path',
        'size',
        'type',
        's3_path',
        'is_migrated',
    ];

    protected $casts = [
        'is_migrated' => 'boolean',
        'size' => 'integer',
    ];
}
