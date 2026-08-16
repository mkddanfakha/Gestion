<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentAuthorizer;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AttachmentController extends Controller
{
    public function __construct(
        protected AttachmentService $attachmentService,
        protected AttachmentAuthorizer $attachmentAuthorizer,
    ) {}

    public function show(Request $request, Attachment $attachment)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $this->attachmentAuthorizer->authorizeView($user, $attachment);

        return $this->attachmentService->stream($attachment, inline: true);
    }

    public function download(Request $request, Attachment $attachment)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $this->attachmentAuthorizer->authorizeView($user, $attachment);

        return $this->attachmentService->download($attachment);
    }

    public function destroy(Request $request, Attachment $attachment)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $this->attachmentAuthorizer->authorizeDelete($user, $attachment);

        try {
            $this->attachmentService->delete($attachment, $user);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['attachment' => $e->getMessage()]);
        }

        return back()->with('success', 'Pièce jointe supprimée.');
    }
}
