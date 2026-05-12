<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user !== null;
    }

    public function view(User $user, Project $project): bool
    {
        return $user !== null;
    }

    public function update(User $user, Project $project): bool
    {
        return $user !== null;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user !== null;
    }
}
