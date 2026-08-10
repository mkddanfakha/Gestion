<?php

namespace App\Modules\NotificationCenter\Policies;

use App\Models\User;
use App\Modules\NotificationCenter\Models\Notification;

class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    public function update(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    public function delete(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }
}
