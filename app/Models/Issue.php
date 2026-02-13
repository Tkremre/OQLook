<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends Model
{
    use HasFactory;

    protected $fillable = [
        'scan_id',
        'code',
        'title',
        'domain',
        'severity',
        'impact',
        'affected_count',
        'recommendation',
        'suggested_oql',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(IssueSample::class);
    }
}
