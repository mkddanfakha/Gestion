<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'description',
        'sku',
        'barcode',
        'price',
        'cost_price',
        'stock_quantity',
        'min_stock_level',
        'unit',
        'location',
        'image',
        'is_active',
        'category_id',
        'expiration_date',
        'alert_threshold_value',
        'alert_threshold_unit',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'is_active' => 'boolean',
        'expiration_date' => 'date',
    ];

    /**
     * Relation avec la catégorie
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relation avec les éléments de vente
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // Supprimer automatiquement les articles de vente quand un produit est supprimé
        static::deleting(function ($product) {
            // Cette méthode sera appelée avant la suppression du produit
            // Les contraintes de clé étrangère avec CASCADE DELETE s'occuperont du reste
            $product->saleItems()->delete();
        });
    }

    /**
     * Vérifier si le stock est faible
     */
    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->min_stock_level;
    }

    /**
     * Vérifier si le produit est expiré
     */
    public function isExpired(): bool
    {
        if (!$this->expiration_date) {
            return false;
        }
        return $this->expiration_date->isPast();
    }

    /**
     * Vérifier si le produit est proche de l'expiration (selon le seuil d'alerte)
     */
    public function isExpiringSoon(): bool
    {
        if (!$this->expiration_date || !$this->alert_threshold_value || !$this->alert_threshold_unit) {
            return false;
        }

        $today = now()->startOfDay();
        $expirationDate = $this->expiration_date->startOfDay();
        
        // Si déjà expiré, retourner true
        if ($expirationDate->isPast()) {
            return true;
        }

        // Calculer la date d'alerte selon l'unité
        $alertDate = clone $expirationDate;
        switch ($this->alert_threshold_unit) {
            case 'days':
                $alertDate->subDays($this->alert_threshold_value);
                break;
            case 'weeks':
                $alertDate->subWeeks($this->alert_threshold_value);
                break;
            case 'months':
                $alertDate->subMonths($this->alert_threshold_value);
                break;
        }

        // Vérifier si on est dans la période d'alerte
        return $today->greaterThanOrEqualTo($alertDate) && $today->lessThanOrEqualTo($expirationDate);
    }

    /**
     * Obtenir le nombre de jours restants avant expiration
     */
    public function getDaysUntilExpirationAttribute(): ?int
    {
        if (!$this->expiration_date) {
            return null;
        }
        return now()->startOfDay()->diffInDays($this->expiration_date->startOfDay(), false);
    }

    /**
     * Calculer la marge bénéficiaire
     */
    public function getProfitMarginAttribute(): float
    {
        if (!$this->cost_price || $this->cost_price == 0) {
            return 0;
        }
        return (($this->price - $this->cost_price) / $this->cost_price) * 100;
    }

    /**
     * Générer automatiquement un SKU unique au format CCNNNN (6 caractères)
     */
    public static function generateSku(string $name, ?int $categoryId = null): string
    {
        // Mapping des catégories vers des codes de 2 lettres
        $categoryCodes = [
            1 => 'EL', // Électronique
            2 => 'VT', // Vêtements
            3 => 'AC', // Accessoires
            4 => 'LI', // Livres
            5 => 'SP', // Sports
            6 => 'MA', // Maison
            7 => 'BE', // Beauté
            8 => 'AU', // Auto
            9 => 'JO', // Jouets
            10 => 'SA', // Santé
        ];

        // Code par défaut si catégorie non trouvée
        $categoryCode = $categoryCodes[$categoryId] ?? 'PR';

        // Trouver le dernier SKU avec ce préfixe de catégorie
        $lastProduct = self::where('sku', 'like', $categoryCode . '%')
            ->orderBy('sku', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastProduct) {
            // Extraire le numéro du dernier SKU (4 derniers caractères)
            $lastSku = $lastProduct->sku;
            $lastNumber = (int) substr($lastSku, -4);
            $nextNumber = $lastNumber + 1;
        }

        // Limiter à 9999 produits par catégorie (4 chiffres)
        if ($nextNumber > 9999) {
            $nextNumber = 1; // Recommencer à 1 si on dépasse 9999
        }

        // Formater le numéro sur 4 chiffres
        $formattedNumber = str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        $sku = $categoryCode . $formattedNumber;

        // Vérifier si le SKU existe déjà et ajuster si nécessaire
        $attempt = 0;
        while (self::where('sku', $sku)->exists() && $attempt < 1000) {
            $attempt++;
            $nextNumber++;
            if ($nextNumber > 9999) {
                $nextNumber = 1;
            }
            $formattedNumber = str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            $sku = $categoryCode . $formattedNumber;
        }

        // Si on atteint le maximum de tentatives, utiliser un timestamp pour garantir l'unicité
        if ($attempt >= 1000) {
            $sku = $categoryCode . str_pad((time() % 10000), 4, '0', STR_PAD_LEFT);
        }

        return $sku;
    }

    /**
     * Enregistrer les collections de médias
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp']);
            // ->singleFile(); // Décommenter pour une seule image principale
    }

    /**
     * Enregistrer les conversions de médias
     */
    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->sharpen(10)
            ->quality(80)
            ->nonQueued();
    }

    /**
     * Obtenir l'URL relative d'un média pour éviter les problèmes CORS
     */
    public static function getMediaUrl($media, string $conversion = ''): ?string
    {
        if (!$media) {
            return null;
        }
        
        $disk = $media->disk;
        
        // Utiliser la méthode native de MediaLibrary pour obtenir l'URL
        // puis convertir en URL relative
        try {
            if ($conversion) {
                // Vérifier si la conversion existe
                $generatedConversions = $media->generated_conversions ?? [];
                if (!isset($generatedConversions[$conversion]) || !$generatedConversions[$conversion]) {
                    // Si la conversion n'existe pas, retourner l'URL de l'image originale
                    $url = $media->getUrl();
                } else {
                    // Utiliser la méthode native pour obtenir l'URL de la conversion
                    $url = $media->getUrl($conversion);
                }
            } else {
                $url = $media->getUrl();
            }
            
            // Convertir l'URL absolue en URL relative pour éviter les problèmes CORS
            if (str_starts_with($url, 'http')) {
                $parsedUrl = parse_url($url);
                $url = $parsedUrl['path'] ?? $url;
            }
            
            // S'assurer que l'URL commence par /
            if (!str_starts_with($url, '/')) {
                $url = '/' . $url;
            }
            
            return $url;
        } catch (\Exception $e) {
            // En cas d'erreur, construire l'URL manuellement à partir des données de la BD
            $mediaId = $media->id;
            $fileName = $media->file_name;
            
            if ($disk === 'media') {
                if ($conversion) {
                    // Vérifier si la conversion existe dans generated_conversions
                    $generatedConversions = $media->generated_conversions ?? [];
                    if (!isset($generatedConversions[$conversion]) || !$generatedConversions[$conversion]) {
                        // Si la conversion n'existe pas, retourner l'URL de l'image originale
                        return '/storage/' . $mediaId . '/' . $fileName;
                    }
                    // Construire le nom de fichier de la conversion selon le format de MediaLibrary
                    $pathInfo = pathinfo($fileName);
                    $conversionFileName = $pathInfo['filename'] . '-' . $conversion . '.' . ($pathInfo['extension'] ?? 'jpg');
                    return '/storage/' . $mediaId . '/conversions/' . $conversionFileName;
                } else {
                    return '/storage/' . $mediaId . '/' . $fileName;
                }
            }
            
            return null;
        }
    }
}
