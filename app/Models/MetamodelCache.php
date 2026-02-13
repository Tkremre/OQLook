<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetamodelCache extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'metamodel_hash',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
