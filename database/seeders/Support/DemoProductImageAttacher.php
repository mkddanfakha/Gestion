<?php

namespace Database\Seeders\Support;

use App\Models\Product;
use Database\Seeders\DemoLocalSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Attache les images de démonstration via Spatie Media Library (collection images, disk media).
 */
final class DemoProductImageAttacher
{
  public const MEDIA_SOURCE = 'demo-local';

  /**
   * @return array{attached: int, skipped: int, total: int, without_image: int}
   */
  public function attachToDemoProducts(?Command $command = null, bool $force = false): array
  {
    $products = Product::query()
      ->where('description', 'like', '%'.DemoLocalSeeder::MARKER.'%')
      ->orderBy('id')
      ->get();

    $attached = 0;
    $skipped = 0;

    foreach ($products as $product) {
      $existing = $product->getMedia('images')->first();

      if ($existing && ! $force) {
        if ($existing->getCustomProperty('source') === self::MEDIA_SOURCE) {
          $skipped++;

          continue;
        }

        if ($existing->getCustomProperty('source') !== self::MEDIA_SOURCE) {
          $command?->warn("Skip {$product->sku} — image non démo déjà présente.");
          $skipped++;

          continue;
        }
      }

      if ($existing && $force) {
        $product->clearMediaCollection('images');
      }

      $jpeg = DemoProductImageGenerator::forProduct($product);
      $fileName = Str::slug($product->sku ?: ('product-'.$product->id)).'.jpg';

      $product
        ->addMediaFromString($jpeg)
        ->usingFileName($fileName)
        ->withCustomProperties(['source' => self::MEDIA_SOURCE])
        ->toMediaCollection('images', 'media');

      $attached++;
      $command?->line("Image attachée : {$product->sku} — {$product->name}");
    }

    $withoutImage = Product::query()
      ->where('description', 'like', '%'.DemoLocalSeeder::MARKER.'%')
      ->get()
      ->filter(fn (Product $product) => $product->getThumbImageUrl() === null)
      ->count();

    return [
      'attached' => $attached,
      'skipped' => $skipped,
      'total' => $products->count(),
      'without_image' => $withoutImage,
    ];
  }
}
