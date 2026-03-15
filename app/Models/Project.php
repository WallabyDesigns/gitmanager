<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'repo_url',
        'local_path',
        'default_branch',
        'auto_deploy',
        'health_url',
        'health_status',
        'health_checked_at',
        'last_checked_at',
        'last_deployed_at',
        'last_deployed_hash',
        'last_error_message',
        'run_composer_install',
        'run_npm_install',
        'run_build_command',
        'build_command',
        'run_test_command',
        'test_command',
        'allow_dependency_updates',
    ];

    protected $casts = [
        'auto_deploy' => 'boolean',
        'run_composer_install' => 'boolean',
        'run_npm_install' => 'boolean',
        'run_build_command' => 'boolean',
        'allow_dependency_updates' => 'boolean',
        'run_test_command' => 'boolean',
        'health_checked_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_deployed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest('started_at');
    }

    public function securityAlerts(): HasMany
    {
        return $this->hasMany(SecurityAlert::class);
    }
}
