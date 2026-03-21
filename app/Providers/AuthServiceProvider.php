<?php

namespace App\Providers;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Policies\DeploymentQueueItemPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Project::class => ProjectPolicy::class,
        DeploymentQueueItem::class => DeploymentQueueItemPolicy::class,
    ];
}
