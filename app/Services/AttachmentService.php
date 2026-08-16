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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use InvalidArgumentException;

/**
 * Attachment Manager — stockage, métadonnées et cycle de vie des fichiers uploadés.
 *
 * Couche backend du Document Manager MKD-Pro. L'aperçu passe par AttachmentController
 * (inline/attachment) puis DocumentPreviewModal côté frontend.
 *
 * @see docs/document-manager.md
 * @see docs/attachments.md
 */
class AttachmentService
{
    public function add(Model $attachable, UploadedFile $file, ?User $user = null): Attachment
    {
        $this->validateFile($file);
        $this->ensureWithinMaxFiles($attachable, 1);

        $disk = $this->disk();
        $storedName = $this->generateStoredName($file);
        $path = $this->buildStoragePath($attachable, $storedName);

        $storedPath = Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        if (!$storedPath) {
            throw new InvalidArgumentException('Impossible de stocker le fichier.');
        }

        try {
            $attachment = Attachment::create([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'disk' => $disk,
                'path' => $storedPath,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'extension' => strtolower($file->getClientOriginalExtension()),
                'size' => $file->getSize(),
                'file_hash' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $user?->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($storedPath);
            throw $e;
        }

        ActivityLogger::logAttachmentAdded($attachable, $attachment, $user);

        return $attachment;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, Attachment>
     */
    public function addMany(Model $attachable, array $files, ?User $user = null): array
    {
        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));

        if ($files === []) {
            return [];
        }

        $this->ensureWithinMaxFiles($attachable, count($files));

        $created = [];
        $uploadedPaths = [];

        try {
            foreach ($files as $file) {
                $this->validateFile($file);

                $disk = $this->disk();
                $storedName = $this->generateStoredName($file);
                $path = $this->buildStoragePath($attachable, $storedName);

                $storedPath = Storage::disk($disk)->putFileAs(
                    dirname($path),
                    $file,
                    basename($path)
                );

                if (!$storedPath) {
                    throw new InvalidArgumentException('Impossible de stocker le fichier.');
                }

                $uploadedPaths[] = ['disk' => $disk, 'path' => $storedPath];

                $attachment = Attachment::create([
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'original_name' => $file->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'disk' => $disk,
                    'path' => $storedPath,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'extension' => strtolower($file->getClientOriginalExtension()),
                    'size' => $file->getSize(),
                    'file_hash' => hash_file('sha256', $file->getRealPath()),
                    'uploaded_by' => $user?->id,
                ]);

                ActivityLogger::logAttachmentAdded($attachable, $attachment, $user);
                $created[] = $attachment;
            }
        } catch (\Throwable $e) {
            foreach ($uploadedPaths as $uploaded) {
                Storage::disk($uploaded['disk'])->delete($uploaded['path']);
            }

            foreach ($created as $attachment) {
                $attachment->delete();
            }

            throw $e;
        }

        return $created;
    }

    public function delete(Attachment $attachment, ?User $user = null): void
    {
        $attachable = $attachment->attachable;
        $disk = $attachment->disk;
        $path = $attachment->path;
        $originalName = $attachment->original_name;

        DB::transaction(function () use ($attachment, $attachable, $disk, $path, $originalName, $user) {
            if ($attachable) {
                ActivityLogger::logAttachmentDeleted($attachable, $originalName, $attachment, $user);
            }

            $attachment->delete();

            if (!Storage::disk($disk)->delete($path)) {
                throw new InvalidArgumentException('Impossible de supprimer le fichier du stockage.');
            }
        });
    }

    public function deleteFor(Model $attachable): void
    {
        $attachments = $attachable->attachments()->get();

        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        }
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        if (!Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404, 'Fichier introuvable.');
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type]
        );
    }

    public function stream(Attachment $attachment, bool $inline = true): StreamedResponse
    {
        if (!Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404, 'Fichier introuvable.');
        }

        $disposition = $inline ? 'inline' : 'attachment';

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => $disposition . '; filename="' . addslashes($attachment->original_name) . '"',
            ]
        );
    }

    public function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Le fichier uploadé n\'est pas valide.');
        }

        $maxSizeKb = config('attachments.max_size', 10240);
        $maxSizeBytes = $maxSizeKb * 1024;

        if ($file->getSize() > $maxSizeBytes) {
            $maxMo = number_format($maxSizeKb / 1024, 0, ',', ' ');
            throw new InvalidArgumentException("Le fichier dépasse la taille maximale de {$maxMo} Mo.");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $blocked = config('attachments.blocked_extensions', []);

        if (in_array($extension, $blocked, true)) {
            throw new InvalidArgumentException('Ce type de fichier n\'est pas autorisé.');
        }

        $allowedExtensions = config('attachments.allowed_extensions', []);

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException('Ce type de fichier n\'est pas autorisé.');
        }

        $mime = $file->getMimeType();
        $allowedMimes = config('attachments.allowed_mimes', []);

        if ($mime && !in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException('Ce type de fichier n\'est pas autorisé.');
        }
    }

    public function resolveAttachableSlug(Model $model): string
    {
        return match ($model::class) {
            Expense::class => 'expenses',
            Quote::class => 'quotes',
            PurchaseOrder::class => 'purchase-orders',
            DeliveryNote::class => 'delivery-notes',
            Customer::class => 'customers',
            Supplier::class => 'suppliers',
            default => Str::kebab(class_basename($model)),
        };
    }

    protected function disk(): string
    {
        return config('attachments.disk', 'local');
    }

    protected function generateStoredName(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return Str::uuid()->toString() . ($extension ? '.' . $extension : '');
    }

    protected function buildStoragePath(Model $attachable, string $storedName): string
    {
        $slug = $this->resolveAttachableSlug($attachable);

        return "attachments/{$slug}/{$attachable->getKey()}/{$storedName}";
    }

    protected function ensureWithinMaxFiles(Model $attachable, int $incomingCount): void
    {
        $maxFiles = config('attachments.max_files', 10);
        $currentCount = $attachable->attachments()->count();

        if (($currentCount + $incomingCount) > $maxFiles) {
            throw new InvalidArgumentException("Nombre maximal de fichiers atteint ({$maxFiles}).");
        }
    }
}
