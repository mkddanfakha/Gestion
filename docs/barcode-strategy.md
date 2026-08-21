# BARCODE STRATEGY — MKD-PRO V1

## Production

| Fonctionnalité | Statut |
|----------------|--------|
| Barcode stocké en base (STRING, zéros conservés) | ✓ |
| Recherche par barcode (`GET /products/barcode/{barcode}`) | ✓ |
| Index SQL sur `products.barcode` | ✓ |
| Saisie manuelle | ✓ |
| Douchette USB (HID clavier) | ✓ |
| Douchette Bluetooth HID | ✓ |
| Inventaire par scan douchette | ✓ |
| Vente par scan douchette | ✓ |
| Bon de commande / bon de livraison par scan | ✓ |

Composants production :

- `BarcodeInput.vue` — saisie manuelle + douchette
- `BarcodeKeyboardScanner` — détection séquence rapide + ENTER
- `useProductBarcodeLookup` — recherche API
- `useDocumentProductBarcode` — ajout aux lignes de document

## Expérimental (DEV uniquement)

| Technologie | Statut |
|-------------|--------|
| Camera phone | ○ |
| BarcodeDetector natif | ○ |
| Quagga2 | ○ |
| html5-qrcode | ○ |
| vue-qrcode-reader | ○ |
| Focus / zoom / résolution experiments | ○ |
| Benchmarks automatisés | ○ |

La **caméra téléphone n'est PAS une dépendance** du système d'inventaire.

Le composant `BarcodeScanner.vue` conserve le code caméra pour les expérimentations DEV ; le bouton caméra est **désactivé par défaut** en production (`showCameraButton: false`).

## Dépendances npm

| Package | Production | DEV |
|---------|------------|-----|
| `@zxing/browser` | Fallback caméra (DEV seulement via BarcodeScanner) | ✓ |
| `@ericblade/quagga2` | — | ✓ |
| `html5-qrcode` | — | ✓ |
| `barcode-detector` (polyfill) | — | ✓ |
| `vue-qrcode-reader` | — | ✓ |

## Documentation associée

- `docs/barcode-scanner-strategy.md` — stratégie détaillée scanner matériel
- `docs/barcode-experiments.md` — historique benchmarks caméra

## Règles métier barcode

- Type : **string** (jamais integer)
- Zéros initiaux conservés (`030000030493` reste `"030000030493"`)
- Formats supportés en scan : EAN-13, EAN-8, UPC-A (validation format côté frontend)
