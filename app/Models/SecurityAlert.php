<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAlert extends Model
{
    protected $fillable = [
        'project_id',
        'github_alert_id',
        'state',
        'severity',
        'package_name',
        'ecosystem',
        'manifest_path',
        'advisory_summary',
        'advisory_url',
        'html_url',
        'fixed_in',
        'dismissed_at',
        'fixed_at',
        'alert_created_at',
        'last_seen_at',
    ];

    protected $casts = [
        'dismissed_at' => 'datetime',
        'fixed_at' => 'datetime',
        'alert_created_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
