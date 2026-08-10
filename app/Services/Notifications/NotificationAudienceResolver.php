<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Modules\NotificationCenter\Services\NotificationAudienceResolver as ModuleResolver;

/** Façade Gestion — méthodes legacy UI Inertia. */
class NotificationAudienceResolver extends ModuleResolver
{
    public function canReceiveInventoryAlerts(User $user): bool
    {
        return $this->canReceiveType($user, 'inventory_alert');
    }

    public function canReceiveInvoiceAlerts(User $user): bool
    {
        return $this->canReceiveType($user, 'invoice_alert');
    }
}
