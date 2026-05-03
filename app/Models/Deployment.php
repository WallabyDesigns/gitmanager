<?php

namespace App\Models;

use App\Support\ConsoleOutput;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'project_id',
        'triggered_by',
        'action',
        'status',
        'from_hash',
        'to_hash',
        'output_log',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected function outputLog(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => ConsoleOutput::withoutPhpWarnings($value),
        );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
