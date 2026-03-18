<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentQueueItem extends Model
{
    protected $fillable = [
        'project_id',
        'queued_by',
        'action',
        'payload',
        'status',
        'position',
        'started_at',
        'finished_at',
        'deployment_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function queuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queued_by');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
