<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueSample extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id',
        'itop_class',
        'itop_id',
        'name',
        'link',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
