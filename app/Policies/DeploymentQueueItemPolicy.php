<?php

namespace App\Policies;

use App\Models\DeploymentQueueItem;
use App\Models\User;

class DeploymentQueueItemPolicy
{
    public function view(User $user, DeploymentQueueItem $item): bool
    {
        return $user->isAdmin() || ($item->project && $item->project->user_id === $user->id);
    }

    public function update(User $user, DeploymentQueueItem $item): bool
    {
        return $this->view($user, $item);
    }
}
