# Barcode Experiments

## Objectif

Tester la faisabilité de la lecture de codes-barres avec la caméra d'un téléphone Android dans le contexte de MKD-Pro (inventaire, vente, réception).

## Résultat

La lecture caméra est considérée comme **expérimentale** et **n'est pas retenue** pour la production actuelle.

Les benchmarks montrent une dépendance forte au matériel (capteur, autofocus, distance, luminosité) et une fiabilité insuffisante pour un inventaire professionnel critique.

## Tests réalisés

| Domaine | Pages / utilitaires DEV |
|---------|-------------------------|
| **BarcodeDetector natif** | Résolution, ROI, contrôles caméra, focus sharpness, manual focus, focus distance mapping, focus × zoom benchmark |
| **Résolution** | `BarcodeDetectorResolutionTest` |
| **Zoom / taille code-barres** | `BarcodeDetectorSizeZoomComparison`, `BarcodeDetectorFocusZoomBenchmark` |
| **Focus** | Manual focus test/experiment, fine focus sweep, distance focus, stability focus repeatability |
| **Fiabilité décodage** | Decode Reliability (Phase 1), Reliability Matrix, Reliability Phase 2 |
| **Quagga2** | Scanner live + benchmark automatisé (7 configs) |
| **html5-qrcode** | Benchmark automatisé (7 configs qrBox × résolution) |
| **Comparaison moteurs** | `BarcodeEngineComparison` — BarcodeDetector vs Quagga2 vs html5-qrcode |
| **Autres** | ZXing fallback, vue-qrcode-reader diagnostic, pipeline diagnostics |

Point d'entrée DEV : **`/dev/barcode-scanner-lab`** (`BarcodeScannerLab.vue`).

Code-barres de référence utilisé dans les benchmarks : `6043000070493` (EAN-13).

## Conclusion

La caméra téléphone peut parfois lire correctement un code-barres EAN-13, mais :

- la fiabilité varie fortement selon l'appareil ;
- la stabilité temporelle est insuffisante pour un flux scan continu ;
- les faux positifs et lectures instables persistent malgré l'optimisation focus/zoom/résolution.

**La fiabilité observée n'est pas suffisante pour en faire une dépendance critique de l'inventaire professionnel.**

## Décision

**Ne pas intégrer le scanner caméra dans le workflow production.**

Les expérimentations, benchmarks et utilitaires DEV sont **conservés** pour référence future et comparaison objective.

## Alternative production

Utiliser une **douchette USB/Bluetooth compatible clavier HID** :

```
Douchette (HID clavier)
        ↓
Saisie rapide + ENTER
        ↓
BarcodeKeyboardScanner / BarcodeInput
        ↓
GET /products/barcode/{barcode}
        ↓
Inventaire / vente / réception
```

## Futur

La lecture caméra pourra être réévaluée ultérieurement avec :

- autre téléphone / autre navigateur ;
- autre bibliothèque ou API native ;
- application mobile native dédiée ;
- amélioration matérielle (module scan dédié).

## Documentation associée

- `docs/barcode-scanner-strategy.md` — stratégie scanner matériel vs caméra
- `docs/barcode-experiments.md` — historique des expériences caméra
