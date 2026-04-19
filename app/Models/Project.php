<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\FtpAccount;

class Project extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'name',
        'project_type',
        'repo_url',
        'site_url',
        'local_path',
        'default_branch',
        'auto_deploy',
        'health_url',
        'health_status',
        'health_issue_message',
        'health_log',
        'health_checked_at',
        'last_checked_at',
        'last_deployed_at',
        'last_deployed_hash',
        'last_error_message',
        'updates_available',
        'updates_checked_at',
        'last_audit_at',
        'permissions_locked',
        'permissions_issue_message',
        'permissions_checked_at',
        'run_composer_install',
        'run_npm_install',
        'run_build_command',
        'build_command',
        'run_test_command',
        'test_command',
        'allow_dependency_updates',
        'exclude_paths',
        'whitelist_paths',
        'ftp_account_id',
        'ftp_root_path',
        'ftp_enabled',
        'ssh_enabled',
        'ssh_port',
        'ssh_root_path',
        'ssh_commands',
        'ignore_migration_table_exists',
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
        'last_successful_deploy_at' => 'datetime',
        'updates_available' => 'boolean',
        'updates_checked_at' => 'datetime',
        'last_audit_at' => 'datetime',
        'permissions_locked' => 'boolean',
        'permissions_checked_at' => 'datetime',
        'ftp_enabled' => 'boolean',
        'ssh_enabled' => 'boolean',
        'ignore_migration_table_exists' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ftpAccount(): BelongsTo
    {
        return $this->belongsTo(FtpAccount::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest('started_at');
    }

    public function securityAlerts(): HasMany
    {
        return $this->hasMany(SecurityAlert::class);
    }

    public function auditIssues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }

    public function permissionsEnforced(): bool
    {
        return ! $this->ftp_enabled && ! $this->ssh_enabled;
    }

    public function hasSuccessfulDeployment(): bool
    {
        return (bool) ($this->last_deployed_at || $this->last_deployed_hash);
    }
}
