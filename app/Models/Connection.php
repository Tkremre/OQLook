<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'itop_url',
        'auth_mode',
        'username',
        'password_encrypted',
        'token_encrypted',
        'connector_url',
        'connector_bearer_encrypted',
        'fallback_config_json',
        'last_scan_time',
    ];

    protected $casts = [
        'password_encrypted' => 'encrypted',
        'token_encrypted' => 'encrypted',
        'connector_bearer_encrypted' => 'encrypted',
        'fallback_config_json' => 'array',
        'last_scan_time' => 'datetime',
    ];

    protected $hidden = [
        'password_encrypted',
        'token_encrypted',
        'connector_bearer_encrypted',
    ];

    public function metamodelCaches(): HasMany
    {
        return $this->hasMany(MetamodelCache::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function issueAcknowledgements(): HasMany
    {
        return $this->hasMany(IssueAcknowledgement::class);
    }

    public function issueObjectAcknowledgements(): HasMany
    {
        return $this->hasMany(IssueObjectAcknowledgement::class);
    }
}
