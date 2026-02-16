<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScanCheckPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_code',
        'enabled',
        'severity_override',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
