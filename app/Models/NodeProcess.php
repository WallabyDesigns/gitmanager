<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeProcess extends Model
{
    public const STATUS_STOPPED = 'stopped';

    public const STATUS_STARTING = 'starting';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CRASHED = 'crashed';

    protected $fillable = [
        'project_id',
        'status',
        'start_command',
        'port',
        'pid',
        'auto_restart',
        'crash_count',
        'last_started_at',
        'last_stopped_at',
        'last_crashed_at',
    ];

    protected $casts = [
        'auto_restart' => 'boolean',
        'pid' => 'integer',
        'port' => 'integer',
        'crash_count' => 'integer',
        'last_started_at' => 'datetime',
        'last_stopped_at' => 'datetime',
        'last_crashed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    public function isCrashed(): bool
    {
        return $this->status === self::STATUS_CRASHED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_STARTING], true);
    }

    public function logPath(): string
    {
        return storage_path('logs/node-processes/'.$this->project_id.'/out.log');
    }

    public function supervisorScriptPath(): string
    {
        return storage_path('logs/node-processes/'.$this->project_id.'/supervisor.sh');
    }
}
