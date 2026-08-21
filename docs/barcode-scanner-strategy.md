# Stratégie code-barres MKD-Pro

## Décision actuelle

La lecture code-barres par caméra de téléphone **n'est pas retenue** pour la production à ce stade.

MKD-Pro conserve la compatibilité avec les **douchettes physiques** pour les commerçants qui souhaitent utiliser un lecteur externe. C'est actuellement la solution de lecture code-barres **recommandée et supportée**.

Cette décision pourra être réévaluée ultérieurement si une solution caméra suffisamment fiable est identifiée et validée sur les appareils réels ciblés.

## Production — douchette physique

Le scanner matériel USB/Bluetooth HID est la solution production.

La douchette est traitée comme un **périphérique clavier** (keyboard wedge / HID selon le matériel) : elle envoie les caractères du code-barres dans le champ de saisie, généralement suivis de la touche Entrée.

### Architecture actuelle

```
DOUCHETTE PHYSIQUE
        ↓
saisie clavier
        ↓
BarcodeScanner.vue
        ↓
barcode-detected
        ↓
useDocumentProductBarcode
        ↓
useProductBarcodeLookup
        ↓
API / recherche produit
        ↓
produit trouvé
        ↓
ajout automatique dans le document
```

Composants impliqués :

- `BarcodeScanner.vue` — ventes, achats, livraisons (caméra désactivée par défaut)
- `BarcodeInput.vue` — inventaire et saisie réutilisable
- `barcodeKeyboardScanner.ts` — détection séquence rapide + Entrée
- `useDocumentProductBarcode.ts` — traitement métier (recherche, ajout, incrément)
- `useProductBarcodeLookup.ts` — appel HTTP `GET /products/barcode/{barcode}`

Documents concernés : vente, bon de commande, bon de livraison, inventaire.

## Pourquoi

- **Fiabilité prioritaire** : en magasin, un scan raté ou ambigu coûte du temps et crée de la friction.
- **Éviter un investissement sans garantie** : les expérimentations caméra n'ont pas produit de résultats suffisamment stables pour justifier une mise en production.
- **Expérience prévisible** : une douchette HID fournit un comportement constant (saisie clavier + Entrée), indépendamment du modèle de téléphone ou des conditions lumineuses.
- **Réouverture possible** : un scanner caméra pourra être réintroduit si une solution validée sur le matériel cible est identifiée.

Les benchmarks caméra restent une base de travail DEV ; leurs résultats ne doivent pas être interprétés comme des garanties générales de fiabilité en production.

## Expérimentations DEV / Historique

Les expérimentations suivantes ont été réalisées **uniquement à titre expérimental/DEV**. Elles **ne font pas partie de l'architecture production actuelle**.

Bibliothèques et moteurs testés :

- BarcodeDetector natif
- Quagga2
- html5-qrcode
- ZXing
- vue-qrcode-reader

Thèmes explorés (pages et utilitaires DEV) :

- benchmarks comparatifs entre moteurs
- tests caméra, flux vidéo, résolution
- focus, zoom, distance, taille d'image
- stabilité temporelle, répétabilité, matrices de fiabilité

Point d'entrée DEV : `/dev/barcode-scanner-lab` et pages associées.

**Constat** : les tests sur téléphone Android réel n'ont pas fourni une fiabilité suffisante (focus, distance, luminosité, stabilité, faux positifs) pour retenir la caméra comme fonctionnalité production.

## Réouverture future

La lecture caméra pourra être réévaluée si :

- une solution suffisamment fiable est identifiée sur les appareils ciblés ;
- les critères de validation (taux de succès, latence, robustesse aux conditions réelles) sont atteints ;
- le coût d'intégration et de maintenance est acceptable par rapport à la douchette.

## Documentation associée

- `docs/barcode-experiments.md` — historique détaillé des expériences
- `docs/barcode-strategy.md` — synthèse technique V1
