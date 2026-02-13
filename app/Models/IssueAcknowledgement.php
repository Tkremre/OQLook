<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'itop_class',
        'issue_code',
        'domain',
        'title',
        'note',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}

