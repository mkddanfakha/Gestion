```text
========================================
PRE-PROD 12.6 — LOCAL TEST DATA
========================================

STATUS: COMPLETE

ENVIRONMENT:
LOCAL ONLY (APP_ENV=local, DB=gestion, user=gestion_app)

SEED COMMAND:
php artisan demo:seed-local --confirmation="OUI — SEED TEST DATA ON gestion"

SEEDER:
- database/seeders/DemoLocalSeeder.php
- app/Console/Commands/SeedDemoLocalCommand.php
- DatabaseSeeder.php (permissions only + renvoi vers demo:seed-local)

PROTECTION:
- Refus si APP_ENV=production
- Phrase d'approbation obligatoire sur base protégée gestion
- Ne passe pas par db:seed (ProtectedSeedCommand)

USERS CREATED:
- administrator (admin@test.mkd-pro.local): PASS
- manager (gestionnaire@test.mkd-pro.local): PASS
- seller (vendeur@test.mkd-pro.local): PASS

PERMISSIONS SYNC:
- admin: bypass (0 pivot) — PASS
- gestionnaire: 47 permissions (RolePresets) — PASS
- vendeur: 16 permissions (RolePresets) — PASS

COMPANY:
- MKD-Pro Démo (Dakar, Sénégal) — PASS

PRODUCTS:
COUNT: 26
CATEGORIES: Alimentation, Boissons, Hygiène, Épicerie, Divers
CONTEXTE: commerce sénégalais (riz, huile, sucre, eau, etc.)
STOCK: ProductStockInitializationService (source de vérité MAIN)

CUSTOMERS:
COUNT: 8
PROFILS: particulier, entreprise, avec/sans téléphone, avec/sans adresse,
         identité sénégalaise, client étranger (CI), client minimal

SUPPLIERS:
COUNT: 4 (3 actifs, 1 inactif)

SALES (FACTURES):
COUNT: 8
STATUTS PAIEMENT: paid=6, partial=1, pending=1
MODES: cash, wave, orange_money, bank_transfer, check, card, sans paiement
CAS: simple, multi-articles, importante, sans client, partielle, échéance aujourd'hui

QUOTES:
COUNT: 5
STATUTS: draft, sent, accepted, rejected, expired (1 chacun)

PURCHASE ORDERS:
COUNT: 5
STATUTS: draft=1, sent=1, confirmed=1, partially_received=1, received=1

DELIVERY NOTES:
COUNT: 3
STATUTS: 1 pending (BC confirmé), 1 validated partiel, 1 validated complet
STOCK: mouvements purchase via PurchaseOrderDeliveryService.validate()

EXPENSES:
COUNT: 3

ALERTS (via GestionGroupedEntityProvider):
- stock_out: 2 entités — PASS
- low_stock: 2 entités — PASS
- product_expired: 1 entité — PASS
- product_expiring: 1 entité — PASS
- invoice_due: 1 entité — PASS

STOCK SCENARIOS:
- normal (stock > seuil): PASS (22 produits)
- low stock: PASS (2 produits)
- out of stock: PASS (2 produits)
- expiration (expiré + proche): PASS (1 + 1)

PDF:
- Facture (invoices.sale): PASS (~33 KB générés, FA2608001)
- Devis (quotes.quote): PASS (~33 KB générés, DE2608001)
- Entreprise MKD-Pro Démo présente dans les templates

EXPORT:
- Routes export PDF clients/fournisseurs/inventaire présentes
- Données source créées — PASS (non testé navigateur)

AUTHENTICATION:
- Hash::check mots de passe des 3 comptes — PASS
- Test navigateur login/logout — MANUEL recommandé

RESPONSIVE:
- Non testé automatiquement (listes longues disponibles pour test manuel)

DARK MODE:
- Non testé automatiquement

BUILD:
PASS (npm run build, exit code 0, ~1m41s)

MIGRATIONS:
Toutes appliquées avant seeding — PASS

SQL / FK / DOUBLONS:
Aucune erreur lors du seeding — PASS

OVH:
NOT TOUCHED

PROTECTED SITE (www.mkd-pro.com):
NOT TOUCHED

PRODUCTION DATABASE:
NOT TOUCHED

DEPLOYMENT:
NOT EXECUTED

NOTES:
- Sale = facture (pas de modèle Invoice séparé)
- Relancer idempotent: php artisan demo:seed-local --confirmation="OUI — SEED TEST DATA ON gestion"
  (users/company/products mis à jour; ventes/devis/BC skip si marqueur [DEMO-LOCAL] déjà présent)

COMPTES DE TEST:
| Rôle          | Email                              | Mot de passe          |
|---------------|------------------------------------|-----------------------|
| admin         | admin@test.mkd-pro.local           | Test1234!Admin        |
| gestionnaire  | gestionnaire@test.mkd-pro.local    | Test1234!Gestionnaire |
| vendeur       | vendeur@test.mkd-pro.local         | Test1234!Vendeur      |

========================================
END PRE-PROD 12.6
========================================
```
