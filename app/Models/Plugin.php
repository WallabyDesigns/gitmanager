<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    public const STATUS_NOT_INSTALLED = 'not_installed';
    public const STATUS_INSTALLING    = 'installing';
    public const STATUS_INSTALLED     = 'installed';
    public const STATUS_UPDATING      = 'updating';
    public const STATUS_ERROR         = 'error';

    protected $fillable = [
        'slug',
        'installed_version',
        'latest_version',
        'status',
        'auto_update',
        'error_message',
        'last_checked_at',
    ];

    protected $casts = [
        'auto_update'     => 'boolean',
        'last_checked_at' => 'datetime',
    ];
}
