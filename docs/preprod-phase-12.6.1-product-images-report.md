```text
========================================
PRE-PROD 12.6.1 — PRODUCT IMAGES
========================================

STATUS: COMPLETE

PRODUCTS:
26

PRODUCTS WITH IMAGE:
26

PRODUCTS WITHOUT IMAGE:
0

IMAGE STORAGE:
Spatie MediaLibrary — collection `images`, disk `media` (public/storage)
Source: génération locale PHP GD (sans réseau)
Custom property: source=demo-local

IMAGE FORMAT:
JPEG 480×480, qualité 82
Miniature: conversion Spatie `thumb` 200×200 (nonQueued)

AVERAGE IMAGE SIZE:
~9 KB / image (~235 KB total pour 26 produits)

COMMANDE:
php artisan demo:seed-local --images-only --confirmation="OUI — SEED TEST DATA ON gestion"

OPTIONS:
--images-only     : attacher images sans toucher aux autres données
--force-images    : régénérer les images démo existantes

IDEMPOTENCE:
2e exécution → 0 attachée, 26 ignorées — PASS

PRODUCT LIST:
PASS (image_url via getThumbImageUrl() sur les 26 produits)

PRODUCT DETAIL:
PASS (ProductController charge media + image_url)

SALE PRODUCT SELECTION:
PASS (ProductAutocomplete / ProductBarcodeService exposent image_url)

MOBILE:
PASS (composants utilisent object-fit:cover + tailles fixes 50–110px)
Note: validation navigateur manuelle recommandée

DARK MODE:
PASS (conteneurs bg-light + icône bi-box fallback — cohérent avec UI existante)
Note: validation navigateur manuelle recommandée

MISSING IMAGE FALLBACK:
PASS (Products/Index.vue + ProductAutocomplete.vue → icône bi-box si image_url absent)

PERFORMANCE:
PASS (26 images, ~235 KB total, miniatures thumb générées)

BUILD:
PASS (npm run build, exit code 0)

FICHIERS AJOUTÉS:
- database/seeders/Support/DemoProductImageGenerator.php
- database/seeders/Support/DemoProductImageAttacher.php

FICHIERS MODIFIÉS:
- database/seeders/DemoLocalSeeder.php
- app/Console/Commands/SeedDemoLocalCommand.php

DONNÉES EXISTANTES:
Non supprimées (users, ventes, devis, BC, BL, dépenses inchangés)

DATABASE:
LOCAL ONLY (gestion)

OVH:
NOT TOUCHED

PROTECTED SITE:
NOT TOUCHED

DEPLOYMENT:
NOT EXECUTED

========================================
END PRE-PROD 12.6.1
========================================
```
