<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;

class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->user_id === $user->id;
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->user_id === $user->id;
    }
}
