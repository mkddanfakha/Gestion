<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class CompanyLogoService
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_SIZE_BYTES = 2 * 1024 * 1024;

    private const MAX_DIMENSION = 800;

    private const STORAGE_DISK = 'media';

    /**
     * Enregistrer ou remplacer le logo de l'entreprise.
     */
    public function store(Company $company, UploadedFile $file): string
    {
        $this->validateImage($file);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = match ($file->getMimeType()) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'png',
            };
        }

        $directory = 'companies/' . $company->id;
        $filename = 'logo.' . $extension;
        $tempPath = $file->getRealPath();

        $optimizedPath = $this->optimizeImage($tempPath, $extension);
        $storedPath = $directory . '/' . $filename;

        try {
            $contents = file_get_contents($optimizedPath);
            if ($contents === false || ! Storage::disk(self::STORAGE_DISK)->put($storedPath, $contents)) {
                throw ValidationException::withMessages([
                    'logo' => 'Impossible d\'importer le logo.',
                ]);
            }

            $oldPath = $company->logo_path;

            $company->update(['logo_path' => $storedPath]);

            if ($oldPath && $oldPath !== $storedPath) {
                $this->deleteFile($oldPath);
            }

            return $storedPath;
        } finally {
            if ($optimizedPath !== $tempPath && is_file($optimizedPath)) {
                @unlink($optimizedPath);
            }
        }
    }

    /**
     * Supprimer le logo de l'entreprise.
     */
    public function delete(Company $company): void
    {
        if (! $company->logo_path) {
            return;
        }

        $path = $company->logo_path;
        $company->update(['logo_path' => null]);
        $this->deleteFile($path);
    }

    /**
     * Valider le fichier image côté serveur.
     */
    public function validateImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'logo' => 'Le fichier est invalide ou corrompu.',
            ]);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'logo' => 'Le fichier est trop volumineux. Taille maximale : 2 Mo.',
            ]);
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'logo' => 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.',
            ]);
        }

        $finfoMime = mime_content_type($file->getRealPath());
        if (! in_array($finfoMime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'logo' => 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.',
            ]);
        }

        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'logo' => 'Le fichier n\'est pas une image lisible.',
            ]);
        }

        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (! in_array($imageInfo[2], $allowedTypes, true)) {
            throw ValidationException::withMessages([
                'logo' => 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.',
            ]);
        }
    }

    /**
     * Optimiser l'image si elle dépasse les dimensions maximales.
     */
    private function optimizeImage(string $sourcePath, string $extension): string
    {
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return $sourcePath;
        }

        [$width, $height] = $imageInfo;

        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return $sourcePath;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'company_logo_') . '.' . $extension;

        Image::load($sourcePath)
            ->fit(Fit::Max, self::MAX_DIMENSION, self::MAX_DIMENSION)
            ->save($tempPath);

        return $tempPath;
    }

    private function deleteFile(string $path): void
    {
        if (Storage::disk(self::STORAGE_DISK)->exists($path)) {
            Storage::disk(self::STORAGE_DISK)->delete($path);
        } elseif (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
