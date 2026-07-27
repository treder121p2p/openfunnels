<?php

namespace App\Policies;

use App\Models\AutomationWorkflow;
use App\Models\User;

class AutomationWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AutomationWorkflow $workflow): bool
    {
        return $workflow->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AutomationWorkflow $workflow): bool
    {
        return $workflow->user_id === $user->id;
    }

    public function delete(User $user, AutomationWorkflow $workflow): bool
    {
        return $workflow->user_id === $user->id;
    }
}
