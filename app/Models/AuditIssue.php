<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditIssue extends Model
{
    protected $fillable = [
        'project_id',
        'tool',
        'status',
        'severity',
        'summary',
        'fix_summary',
        'found_count',
        'fixed_count',
        'remaining_count',
        'detected_at',
        'resolved_at',
        'last_seen_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
