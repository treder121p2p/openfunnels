<?php

namespace App\Policies;

use App\Models\AutomationRun;
use App\Models\User;

class AutomationRunPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AutomationRun $run): bool
    {
        return $run->user_id === $user->id;
    }

    public function update(User $user, AutomationRun $run): bool
    {
        return $run->user_id === $user->id;
    }
}
