<?php

namespace Database\Seeders\Support;

use App\Models\Product;
use RuntimeException;

/**
 * Génère des visuels génériques (sans marque) pour les produits de démonstration.
 * Utilise GD — aucune dépendance réseau.
 */
final class DemoProductImageGenerator
{
  private const WIDTH = 480;

  private const HEIGHT = 480;

  /** @var array<string, array{title: string, subtitle: string, shape: string, bg: array{0:int,1:int,2:int}, accent: array{0:int,1:int,2:int}}> */
  private const PRESETS = [
    'riz brisure 25 kg' => ['title' => 'RIZ', 'subtitle' => '25 kg', 'shape' => 'bag', 'bg' => [248, 236, 210], 'accent' => [196, 154, 88]],
    'riz parfumé 5 kg' => ['title' => 'RIZ', 'subtitle' => '5 kg', 'shape' => 'bag', 'bg' => [250, 242, 220], 'accent' => [210, 175, 110]],
    'huile végétale 5 l' => ['title' => 'HUILE', 'subtitle' => '5 L', 'shape' => 'jerrycan', 'bg' => [255, 248, 220], 'accent' => [218, 165, 32]],
    'huile végétale 1 l' => ['title' => 'HUILE', 'subtitle' => '1 L', 'shape' => 'bottle', 'bg' => [255, 250, 230], 'accent' => [230, 180, 40]],
    'sucre en morceaux 1 kg' => ['title' => 'SUCRE', 'subtitle' => '1 kg', 'shape' => 'box', 'bg' => [252, 252, 250], 'accent' => [200, 200, 210]],
    'lait en poudre 400 g' => ['title' => 'LAIT', 'subtitle' => '400 g', 'shape' => 'box', 'bg' => [235, 245, 255], 'accent' => [70, 130, 200]],
    'café torréfié 250 g' => ['title' => 'CAFÉ', 'subtitle' => '250 g', 'shape' => 'pouch', 'bg' => [245, 230, 220], 'accent' => [120, 72, 40]],
    'thé lipton 25 sachets' => ['title' => 'THÉ', 'subtitle' => '25 sach.', 'shape' => 'box', 'bg' => [232, 248, 236], 'accent' => [56, 142, 90]],
    'eau minérale 1,5 l' => ['title' => 'EAU', 'subtitle' => '1,5 L', 'shape' => 'bottle', 'bg' => [230, 245, 255], 'accent' => [80, 160, 220]],
    'jus de bissap 1 l' => ['title' => 'BISSAP', 'subtitle' => '1 L', 'shape' => 'bottle', 'bg' => [255, 232, 245], 'accent' => [180, 50, 110]],
    'boisson gazeuse 1,5 l' => ['title' => 'SODA', 'subtitle' => '1,5 L', 'shape' => 'bottle', 'bg' => [255, 240, 230], 'accent' => [220, 90, 50]],
    'biscuit fourré 150 g' => ['title' => 'BISCUIT', 'subtitle' => '150 g', 'shape' => 'pouch', 'bg' => [255, 248, 230], 'accent' => [210, 150, 70]],
    'savon de marseille 200 g' => ['title' => 'SAVON', 'subtitle' => '200 g', 'shape' => 'bar', 'bg' => [245, 250, 255], 'accent' => [120, 170, 210]],
    'lessive en poudre 1 kg' => ['title' => 'LESSIVE', 'subtitle' => '1 kg', 'shape' => 'box', 'bg' => [230, 240, 255], 'accent' => [60, 120, 200]],
    'dentifrice 75 ml' => ['title' => 'DENTIFRICE', 'subtitle' => '75 ml', 'shape' => 'tube', 'bg' => [240, 248, 255], 'accent' => [90, 150, 220]],
    'papier hygiénique x12' => ['title' => 'PAPIER', 'subtitle' => 'x12', 'shape' => 'rolls', 'bg' => [248, 248, 248], 'accent' => [200, 200, 200]],
    'tomate concentrée 70 g' => ['title' => 'TOMATE', 'subtitle' => '70 g', 'shape' => 'can', 'bg' => [255, 235, 230], 'accent' => [200, 50, 40]],
    'spaghetti 500 g' => ['title' => 'PÂTES', 'subtitle' => '500 g', 'shape' => 'pouch', 'bg' => [255, 250, 235], 'accent' => [220, 180, 90]],
    'sardines à l\'huile 125 g' => ['title' => 'SARDINES', 'subtitle' => '125 g', 'shape' => 'can', 'bg' => [235, 240, 245], 'accent' => [90, 120, 150]],
    'mayonnaise 340 g' => ['title' => 'MAYO', 'subtitle' => '340 g', 'shape' => 'jar', 'bg' => [255, 252, 240], 'accent' => [240, 210, 120]],
    'farine de blé 1 kg' => ['title' => 'FARINE', 'subtitle' => '1 kg', 'shape' => 'bag', 'bg' => [252, 248, 240], 'accent' => [210, 190, 150]],
    'savon liquide 500 ml' => ['title' => 'SAVON LIQ.', 'subtitle' => '500 ml', 'shape' => 'pump', 'bg' => [235, 250, 245], 'accent' => [80, 170, 140]],
    'yaourt nature 4x125 g' => ['title' => 'YAOURT', 'subtitle' => '4x125 g', 'shape' => 'cups', 'bg' => [245, 248, 255], 'accent' => [180, 200, 230]],
    'jus de gingembre 1 l' => ['title' => 'GINGEMBRE', 'subtitle' => '1 L', 'shape' => 'bottle', 'bg' => [255, 245, 230], 'accent' => [210, 140, 60]],
    'allumettes sécurité' => ['title' => 'ALLUMETTES', 'subtitle' => 'Boîte', 'shape' => 'matchbox', 'bg' => [255, 240, 240], 'accent' => [200, 60, 60]],
    'sacs plastique 50 pcs' => ['title' => 'SACS', 'subtitle' => '50 pcs', 'shape' => 'stack', 'bg' => [240, 248, 255], 'accent' => [140, 180, 210]],
  ];

  public static function forProduct(Product $product): string
  {
    $key = self::normalizeKey($product->name);
    $preset = self::PRESETS[$key] ?? null;

    if ($preset === null) {
      $preset = [
        'title' => self::abbreviate($product->name),
        'subtitle' => strtoupper((string) ($product->unit ?: 'DÉMO')),
        'shape' => 'box',
        'bg' => [245, 245, 245],
        'accent' => [120, 120, 120],
      ];
    }

    return self::render($preset);
  }

  public static function render(array $preset): string
  {
    if (! extension_loaded('gd')) {
      throw new RuntimeException('Extension PHP GD requise pour générer les images de démonstration.');
    }

    $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
    if ($image === false) {
      throw new RuntimeException('Impossible de créer l\'image GD.');
    }

    [$bgR, $bgG, $bgB] = $preset['bg'];
    [$acR, $acG, $acB] = $preset['accent'];

    $bg = imagecolorallocate($image, $bgR, $bgG, $bgB);
    $accent = imagecolorallocate($image, $acR, $acG, $acB);
    $shadow = imagecolorallocatealpha($image, 30, 30, 30, 90);
    $white = imagecolorallocate($image, 255, 255, 255);
    $textDark = imagecolorallocate($image, 45, 45, 45);
    $textLight = imagecolorallocate($image, 250, 250, 250);

    imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $bg);

    // Bandeau décoratif
    imagefilledrectangle($image, 0, 0, self::WIDTH, 56, $accent);

    self::drawShape($image, $preset['shape'], $accent, $shadow, $white);

    // Titre produit
    $title = $preset['title'];
    $subtitle = $preset['subtitle'];
    $titleSize = strlen($title) > 10 ? 4 : 5;
    imagestring($image, $titleSize, 24, 72, $title, $textDark);
    imagestring($image, 3, 24, 108, $subtitle, $textDark);

    // Badge démo
    imagefilledrectangle($image, self::WIDTH - 118, self::HEIGHT - 42, self::WIDTH - 16, self::HEIGHT - 16, $accent);
    imagestring($image, 2, self::WIDTH - 108, self::HEIGHT - 34, 'MKD DEMO', $textLight);

    ob_start();
    imagejpeg($image, null, 82);
    $bytes = ob_get_clean();
    imagedestroy($image);

    if ($bytes === false || $bytes === '') {
      throw new RuntimeException('Échec de génération JPEG.');
    }

    return $bytes;
  }

  private static function normalizeKey(string $name): string
  {
    return mb_strtolower(trim($name));
  }

  private static function abbreviate(string $name): string
  {
    $upper = mb_strtoupper($name);

    return mb_strlen($upper) > 14 ? mb_substr($upper, 0, 14) : $upper;
  }

  /**
   * @param  \GdImage  $image
   */
  private static function drawShape($image, string $shape, int $accent, int $shadow, int $white): void
  {
    $cx = (int) (self::WIDTH / 2);
    $cy = (int) (self::HEIGHT / 2) + 30;

    match ($shape) {
      'bag' => self::drawBag($image, $cx, $cy, $accent, $shadow, $white),
      'bottle' => self::drawBottle($image, $cx, $cy, $accent, $shadow, $white),
      'jerrycan' => self::drawJerrycan($image, $cx, $cy, $accent, $shadow, $white),
      'box' => self::drawBox($image, $cx, $cy, $accent, $shadow, $white),
      'pouch' => self::drawPouch($image, $cx, $cy, $accent, $shadow, $white),
      'bar' => self::drawBar($image, $cx, $cy, $accent, $shadow, $white),
      'tube' => self::drawTube($image, $cx, $cy, $accent, $shadow, $white),
      'rolls' => self::drawRolls($image, $cx, $cy, $accent, $shadow, $white),
      'can' => self::drawCan($image, $cx, $cy, $accent, $shadow, $white),
      'jar' => self::drawJar($image, $cx, $cy, $accent, $shadow, $white),
      'pump' => self::drawPump($image, $cx, $cy, $accent, $shadow, $white),
      'cups' => self::drawCups($image, $cx, $cy, $accent, $shadow, $white),
      'matchbox' => self::drawMatchbox($image, $cx, $cy, $accent, $shadow, $white),
      'stack' => self::drawStack($image, $cx, $cy, $accent, $shadow, $white),
      default => self::drawBox($image, $cx, $cy, $accent, $shadow, $white),
    };
  }

  /** @param \GdImage $image */
  private static function drawBag($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 95, $cy - 70, $cx + 95, $cy + 90, $shadow);
    imagefilledrectangle($image, $cx - 100, $cy - 75, $cx + 100, $cy + 85, $white);
    imagefilledrectangle($image, $cx - 80, $cy - 95, $cx + 80, $cy - 75, $accent);
    imagerectangle($image, $cx - 100, $cy - 75, $cx + 100, $cy + 85, $accent);
  }

  /** @param \GdImage $image */
  private static function drawBottle($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 38, $cy - 95, $cx + 38, $cy + 95, $shadow);
    imagefilledrectangle($image, $cx - 42, $cy - 100, $cx + 42, $cy + 90, $white);
    imagefilledrectangle($image, $cx - 18, $cy - 125, $cx + 18, $cy - 100, $accent);
    imagerectangle($image, $cx - 42, $cy - 100, $cx + 42, $cy + 90, $accent);
  }

  /** @param \GdImage $image */
  private static function drawJerrycan($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 78, $cy - 68, $cx + 78, $cy + 88, $shadow);
    imagefilledrectangle($image, $cx - 82, $cy - 72, $cx + 82, $cy + 84, $white);
    imagefilledellipse($image, $cx, $cy - 88, 36, 24, $accent);
    imagerectangle($image, $cx - 82, $cy - 72, $cx + 82, $cy + 84, $accent);
  }

  /** @param \GdImage $image */
  private static function drawBox($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 88, $cy - 58, $cx + 88, $cy + 78, $shadow);
    imagefilledrectangle($image, $cx - 92, $cy - 62, $cx + 92, $cy + 74, $white);
    imagefilledrectangle($image, $cx - 92, $cy - 62, $cx + 92, $cy - 38, $accent);
    imagerectangle($image, $cx - 92, $cy - 62, $cx + 92, $cy + 74, $accent);
  }

  /** @param \GdImage $image */
  private static function drawPouch($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 105, $cy - 45, $cx + 105, $cy + 65, $shadow);
    imagefilledrectangle($image, $cx - 110, $cy - 50, $cx + 110, $cy + 60, $white);
    imagefilledrectangle($image, $cx - 70, $cy - 68, $cx + 70, $cy - 50, $accent);
    imagerectangle($image, $cx - 110, $cy - 50, $cx + 110, $cy + 60, $accent);
  }

  /** @param \GdImage $image */
  private static function drawBar($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 70, $cy - 35, $cx + 70, $cy + 35, $shadow);
    imagefilledrectangle($image, $cx - 75, $cy - 40, $cx + 75, $cy + 30, $white);
    imagefilledrectangle($image, $cx - 75, $cy - 40, $cx + 75, $cy - 24, $accent);
    imagerectangle($image, $cx - 75, $cy - 40, $cx + 75, $cy + 30, $accent);
  }

  /** @param \GdImage $image */
  private static function drawTube($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 95, $cy - 22, $cx + 95, $cy + 22, $shadow);
    imagefilledrectangle($image, $cx - 100, $cy - 26, $cx + 100, $cy + 18, $white);
    imagefilledrectangle($image, $cx + 72, $cy - 18, $cx + 100, $cy + 10, $accent);
    imagerectangle($image, $cx - 100, $cy - 26, $cx + 100, $cy + 18, $accent);
  }

  /** @param \GdImage $image */
  private static function drawRolls($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    foreach ([-55, 0, 55] as $offset) {
      imagefilledellipse($image, $cx + $offset, $cy + 8, 58, 58, $shadow);
      imagefilledellipse($image, $cx + $offset, $cy, 58, 58, $white);
      imageellipse($image, $cx + $offset, $cy, 58, 58, $accent);
    }
  }

  /** @param \GdImage $image */
  private static function drawCan($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 58, $cy - 48, $cx + 58, $cy + 48, $shadow);
    imagefilledrectangle($image, $cx - 62, $cy - 52, $cx + 62, $cy + 44, $white);
    imagefilledrectangle($image, $cx - 62, $cy - 52, $cx + 62, $cy - 34, $accent);
    imagerectangle($image, $cx - 62, $cy - 52, $cx + 62, $cy + 44, $accent);
  }

  /** @param \GdImage $image */
  private static function drawJar($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 52, $cy - 42, $cx + 52, $cy + 62, $shadow);
    imagefilledrectangle($image, $cx - 56, $cy - 46, $cx + 56, $cy + 58, $white);
    imagefilledrectangle($image, $cx - 56, $cy - 68, $cx + 56, $cy - 46, $accent);
    imagerectangle($image, $cx - 56, $cy - 46, $cx + 56, $cy + 58, $accent);
  }

  /** @param \GdImage $image */
  private static function drawPump($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    self::drawBottle($image, $cx, $cy + 10, $accent, $shadow, $white);
    imagefilledrectangle($image, $cx - 8, $cy - 118, $cx + 8, $cy - 98, $accent);
    imagefilledrectangle($image, $cx + 4, $cy - 108, $cx + 34, $cy - 100, $accent);
  }

  /** @param \GdImage $image */
  private static function drawCups($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    foreach ([-48, 48] as $offset) {
      imagefilledrectangle($image, $cx + $offset - 34, $cy - 28, $cx + $offset + 34, $cy + 38, $shadow);
      imagefilledrectangle($image, $cx + $offset - 38, $cy - 32, $cx + $offset + 38, $cy + 34, $white);
      imagefilledellipse($image, $cx + $offset, $cy - 32, 76, 20, $accent);
      imagerectangle($image, $cx + $offset - 38, $cy - 32, $cx + $offset + 38, $cy + 34, $accent);
    }
  }

  /** @param \GdImage $image */
  private static function drawMatchbox($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    imagefilledrectangle($image, $cx - 78, $cy - 42, $cx + 78, $cy + 42, $shadow);
    imagefilledrectangle($image, $cx - 82, $cy - 46, $cx + 82, $cy + 38, $accent);
    imagefilledrectangle($image, $cx - 70, $cy - 34, $cx + 70, $cy + 30, $white);
    imagerectangle($image, $cx - 82, $cy - 46, $cx + 82, $cy + 38, $accent);
  }

  /** @param \GdImage $image */
  private static function drawStack($image, int $cx, int $cy, int $accent, int $shadow, int $white): void
  {
    foreach ([-24, 0, 24] as $offset) {
      imagefilledrectangle($image, $cx - 88 + $offset, $cy - 40 + $offset, $cx + 88 + $offset, $cy + 40 + $offset, $shadow);
      imagefilledrectangle($image, $cx - 92 + $offset, $cy - 44 + $offset, $cx + 92 + $offset, $cy + 36 + $offset, $white);
      imagerectangle($image, $cx - 92 + $offset, $cy - 44 + $offset, $cx + 92 + $offset, $cy + 36 + $offset, $accent);
    }
  }
}
