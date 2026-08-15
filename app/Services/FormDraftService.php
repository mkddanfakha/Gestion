<?php

namespace App\Services;

use App\Models\FormDraft;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FormDraftService
{
    public function findForScope(
        User $user,
        string $formType,
        string $mode,
        ?int $entityId
    ): ?FormDraft {
        $draft = FormDraft::query()
            ->where('user_id', $user->id)
            ->where('form_type', $formType)
            ->where('mode', $mode)
            ->when(
                $entityId === null,
                fn ($query) => $query->whereNull('entity_id'),
                fn ($query) => $query->where('entity_id', $entityId)
            )
            ->first();

        if (!$draft) {
            return null;
        }

        if ($draft->isExpired()) {
            $draft->delete();

            return null;
        }

        return $draft;
    }

    public function upsert(
        User $user,
        string $formType,
        string $mode,
        ?int $entityId,
        array $data,
        ?string $instanceId = null,
        ?int $incomingVersion = null
    ): FormDraft {
        $existing = $this->findForScope($user, $formType, $mode, $entityId);

        if ($existing && $instanceId && $existing->instance_id && $existing->instance_id !== $instanceId) {
            $existingUpdatedAt = $existing->updated_at?->timestamp ?? 0;

            if ($incomingVersion !== null && $existing->version > $incomingVersion) {
                return $existing;
            }

            if ($incomingVersion !== null && $existing->version === $incomingVersion && $existingUpdatedAt > now()->timestamp) {
                return $existing;
            }
        }

        $expiresAt = now()->addDays((int) config('drafts.expiration_days', 7));

        if ($existing) {
            $existing->fill([
                'data' => $data,
                'version' => max($existing->version + 1, $incomingVersion ?? ($existing->version + 1)),
                'instance_id' => $instanceId ?? $existing->instance_id,
                'expires_at' => $expiresAt,
            ]);
            $existing->save();

            return $existing->fresh();
        }

        return FormDraft::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'form_type' => $formType,
            'mode' => $mode,
            'entity_id' => $entityId,
            'data' => $data,
            'version' => $incomingVersion ?? 1,
            'instance_id' => $instanceId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function deleteForScope(
        User $user,
        string $formType,
        string $mode,
        ?int $entityId
    ): void {
        FormDraft::query()
            ->where('user_id', $user->id)
            ->where('form_type', $formType)
            ->where('mode', $mode)
            ->when(
                $entityId === null,
                fn ($query) => $query->whereNull('entity_id'),
                fn ($query) => $query->where('entity_id', $entityId)
            )
            ->delete();
    }

    public function deleteById(User $user, string $draftId): bool
    {
        return FormDraft::query()
            ->where('user_id', $user->id)
            ->where('id', $draftId)
            ->delete() > 0;
    }

    public function cleanupExpired(?int $userId = null): int
    {
        return FormDraft::query()
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }

    public function assertAllowedFormType(string $formType): void
    {
        $allowed = config('drafts.form_types', []);

        if (!in_array($formType, $allowed, true)) {
            abort(422, 'Type de formulaire non autorisé.');
        }
    }

    public function supportsServerSync(string $formType): bool
    {
        return in_array($formType, config('drafts.server_sync_types', []), true);
    }
}
