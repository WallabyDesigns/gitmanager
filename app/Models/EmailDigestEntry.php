<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDigestEntry extends Model
{
    protected $fillable = [
        'project_id',
        'recipient_key',
        'recipients',
        'category',
        'source_key',
        'summary',
        'details',
        'occurred_at',
        'sent_at',
    ];

    protected $casts = [
        'recipients' => 'array',
        'details' => 'array',
        'occurred_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
