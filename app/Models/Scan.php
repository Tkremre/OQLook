<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'started_at',
        'finished_at',
        'mode',
        'summary_json',
        'scores_json',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary_json' => 'array',
        'scores_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
