<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class CompanyAssetService
{
    public const TYPE_LOGO = 'logo';

    public const TYPE_SIGNATURE = 'signature';

    public const TYPE_STAMP = 'stamp';

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_SIZE_BYTES = 2 * 1024 * 1024;

    private const STORAGE_DISK = 'media';

    /** @var array<string, array{field: string, filename: string, max_width: int, max_height: int, error_key: string}> */
    private const ASSET_CONFIG = [
        self::TYPE_LOGO => [
            'field' => 'logo_path',
            'filename' => 'logo',
            'max_width' => 800,
            'max_height' => 800,
            'error_key' => 'logo',
        ],
        self::TYPE_SIGNATURE => [
            'field' => 'signature_path',
            'filename' => 'signature',
            'max_width' => 600,
            'max_height' => 200,
            'error_key' => 'signature',
        ],
        self::TYPE_STAMP => [
            'field' => 'stamp_path',
            'filename' => 'stamp',
            'max_width' => 280,
            'max_height' => 280,
            'error_key' => 'stamp',
        ],
    ];

    public function store(Company $company, UploadedFile $file, string $type): string
    {
        $config = $this->configFor($type);
        $this->validateImage($file, $config['error_key']);

        $extension = $this->resolveExtension($file);
        $directory = 'companies/' . $company->id;
        $filename = $config['filename'] . '.' . $extension;
        $tempPath = $file->getRealPath();

        $optimizedPath = $this->optimizeImage(
            $tempPath,
            $extension,
            $config['max_width'],
            $config['max_height'],
        );

        $storedPath = $directory . '/' . $filename;

        try {
            $contents = file_get_contents($optimizedPath);
            if ($contents === false || ! Storage::disk(self::STORAGE_DISK)->put($storedPath, $contents)) {
                throw ValidationException::withMessages([
                    $config['error_key'] => 'Impossible d\'importer le fichier.',
                ]);
            }

            $field = $config['field'];
            $oldPath = $company->{$field};

            $company->update([$field => $storedPath]);

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

    public function delete(Company $company, string $type): void
    {
        $config = $this->configFor($type);
        $field = $config['field'];

        if (! $company->{$field}) {
            return;
        }

        $path = $company->{$field};
        $company->update([$field => null]);
        $this->deleteFile($path);
    }

    public function validateImage(UploadedFile $file, string $errorKey = 'file'): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $errorKey => 'Le fichier est invalide ou corrompu.',
            ]);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                $errorKey => 'Le fichier est trop volumineux. Taille maximale : 2 Mo.',
            ]);
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                $errorKey => 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.',
            ]);
        }

        $finfoMime = mime_content_type($file->getRealPath());
        if (! in_array($finfoMime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                $errorKey => 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.',
            ]);
        }

        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                $errorKey => 'Le fichier n\'est pas une image lisible.',
            ]);
        }

        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (! in_array($imageInfo[2], $allowedTypes, true)) {
            throw ValidationException::withMessages([
                $errorKey => 'Format non pris en charge. Formats acceptés : JPG, JPEG, PNG, WEBP.',
            ]);
        }
    }

    private function configFor(string $type): array
    {
        if (! isset(self::ASSET_CONFIG[$type])) {
            throw new \InvalidArgumentException("Type d'asset inconnu : {$type}");
        }

        return self::ASSET_CONFIG[$type];
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = match ($file->getMimeType()) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'png',
            };
        }

        return $extension;
    }

    private function optimizeImage(string $sourcePath, string $extension, int $maxWidth, int $maxHeight): string
    {
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return $sourcePath;
        }

        [$width, $height] = $imageInfo;

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $sourcePath;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'company_asset_') . '.' . $extension;

        Image::load($sourcePath)
            ->fit(Fit::Max, $maxWidth, $maxHeight)
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
