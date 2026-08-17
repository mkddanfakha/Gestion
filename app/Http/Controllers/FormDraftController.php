<?php

namespace App\Http\Controllers;

use App\Models\FormDraft;
use App\Services\FormDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormDraftController extends Controller
{
    public function __construct(
        private readonly FormDraftService $draftService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'form_type' => 'required|string|max:64',
            'mode' => 'required|string|in:create,edit',
            'entity_id' => 'nullable|integer|min:1',
            'scope_context' => 'nullable|string|max:128',
        ]);

        $this->draftService->assertAllowedFormType($validated['form_type']);

        $draft = $this->draftService->findForScope(
            $request->user(),
            $validated['form_type'],
            $validated['mode'],
            $validated['entity_id'] ?? null,
            $validated['scope_context'] ?? null,
        );

        if (!$draft) {
            return response()->json(['draft' => null]);
        }

        return response()->json([
            'draft' => $this->serializeDraft($draft),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'form_type' => 'required|string|max:64',
            'mode' => 'required|string|in:create,edit',
            'entity_id' => 'nullable|integer|min:1',
            'scope_context' => 'nullable|string|max:128',
            'data' => 'required|array',
            'version' => 'nullable|integer|min:1',
            'instance_id' => 'nullable|string|max:64',
        ]);

        $this->draftService->assertAllowedFormType($validated['form_type']);

        if (!$this->draftService->supportsServerSync($validated['form_type'])) {
            abort(422, 'Ce type de formulaire ne supporte pas la synchronisation serveur.');
        }

        $draft = $this->draftService->upsert(
            $request->user(),
            $validated['form_type'],
            $validated['mode'],
            $validated['entity_id'] ?? null,
            $validated['data'],
            $validated['instance_id'] ?? null,
            $validated['version'] ?? null,
            $validated['scope_context'] ?? null,
        );

        return response()->json([
            'draft' => $this->serializeDraft($draft),
        ]);
    }

    public function destroy(Request $request, FormDraft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(403, 'Accès refusé.');
        }

        $draft->delete();

        return response()->json(['success' => true]);
    }

    public function destroyByScope(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'form_type' => 'required|string|max:64',
            'mode' => 'required|string|in:create,edit',
            'entity_id' => 'nullable|integer|min:1',
            'scope_context' => 'nullable|string|max:128',
        ]);

        $this->draftService->assertAllowedFormType($validated['form_type']);

        $this->draftService->deleteForScope(
            $request->user(),
            $validated['form_type'],
            $validated['mode'],
            $validated['entity_id'] ?? null,
            $validated['scope_context'] ?? null,
        );

        return response()->json(['success' => true]);
    }

    private function serializeDraft(FormDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'userId' => $draft->user_id,
            'formType' => $draft->form_type,
            'mode' => $draft->mode,
            'entityId' => $draft->entity_id,
            'scopeContext' => $draft->scope_context,
            'data' => $draft->data,
            'version' => $draft->version,
            'instanceId' => $draft->instance_id,
            'createdAt' => $draft->created_at?->toIso8601String(),
            'updatedAt' => $draft->updated_at?->toIso8601String(),
            'expiresAt' => $draft->expires_at?->toIso8601String(),
        ];
    }
}
