<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;

class AttachmentAuthorizer
{
    /**
     * @var array<class-string, array{resource: string, view: string, update: string, delete: string}>
     */
    private const RESOURCE_MAP = [
        Expense::class => [
            'resource' => 'expenses',
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
        ],
        Quote::class => [
            'resource' => 'quotes',
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
        ],
        PurchaseOrder::class => [
            'resource' => 'purchase-orders',
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
        ],
        DeliveryNote::class => [
            'resource' => 'delivery-notes',
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
        ],
        Customer::class => [
            'resource' => 'customers',
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
        ],
        Supplier::class => [
            'resource' => 'suppliers',
            'view' => 'view',
            'update' => 'update',
            'delete' => 'delete',
        ],
    ];

    public function authorizeView(User $user, Attachment $attachment): void
    {
        $this->authorizeAction($user, $attachment, 'view');
    }

    public function authorizeDelete(User $user, Attachment $attachment): void
    {
        $this->authorizeAction($user, $attachment, 'update');
    }

    public function authorizeUpload(User $user, Attachment $attachment): void
    {
        $this->authorizeAction($user, $attachment, 'update');
    }

    protected function authorizeAction(User $user, Attachment $attachment, string $actionKey): void
    {
        $attachable = $attachment->attachable;

        if (!$attachable) {
            abort(404, 'Pièce jointe introuvable.');
        }

        $config = self::RESOURCE_MAP[$attachable::class] ?? null;

        if (!$config) {
            abort(403, 'Type de pièce jointe non pris en charge.');
        }

        $user->refresh();

        if (!$user->hasPermission($config['resource'], $config[$actionKey])) {
            abort(403, 'Accès refusé.');
        }

        $this->authorizeAttachableScope($user, $attachable);
    }

    public function authorizeAttachable(User $user, object $attachable, string $actionKey): void
    {
        $config = self::RESOURCE_MAP[$attachable::class] ?? null;

        if (!$config) {
            abort(403, 'Type de pièce jointe non pris en charge.');
        }

        $user->refresh();

        if (!$user->hasPermission($config['resource'], $config[$actionKey])) {
            abort(403, 'Accès refusé.');
        }

        $this->authorizeAttachableScope($user, $attachable);
    }

    protected function authorizeAttachableScope(User $user, object $attachable): void
    {
        if ($attachable instanceof Expense) {
            if ($user->isGestionnaire() && $attachable->user_id !== $user->id) {
                abort(403, 'Accès refusé. Vous ne pouvez accéder qu\'à vos propres dépenses.');
            }
        }
    }

    public function authorizeAttachableForRequest(Request $request, object $attachable, string $actionKey): void
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $this->authorizeAttachable($user, $attachable, $actionKey);
    }
}
