# RBAC — Durcissement des routes sensibles (Phase F)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Références :** [`rbac-audit.md`](./rbac-audit.md), [`rbac-authorization.md`](./rbac-authorization.md)

---

## 1. Routes auditées

Scan complet de `routes/web.php`, `routes/auth.php`, `routes/settings.php`, `app/Modules/NotificationCenter/Http/Routes/notifications.php` et des contrôleurs associés.

**~95 routes POST/PUT/PATCH/DELETE** et endpoints GET sensibles (exports, previews, uploads, admin, dev).

---

## 2. Routes corrigées

| Route | Méthode | Permission finale | Correction |
|-------|---------|-------------------|------------|
| `/products/upload-image` | POST | `products.create` **ou** `products.update` | `checkAnyPermission` ajouté dans `ProductController` |
| `/products/generate-sku` | POST | `products.create` **ou** `products.update` | Idem |
| `/delivery-notes/{id}/validate` | POST | `delivery-notes.validate` | Suppression `EnsureUserIsAdmin` sur la route ; contrôle déjà présent dans le contrôleur |
| `/dev/*` (27 routes) | GET | Admin uniquement (local) | `EnsureUserIsAdmin` ajouté au groupe local |
| `/api/notifications/test` | POST | Admin uniquement | `EnsureUserIsAdmin` sur la route |
| `/notifications/test` | POST | Admin uniquement | Idem (legacy) |

---

## 3. Routes volontairement laissées auth-only (justifiées)

| Route / zone | Raison |
|--------------|--------|
| `FormDraftController` (`/drafts/*`) | Brouillons scopés par `user_id` ; isolation utilisateur suffisante |
| `NotificationApiController` (mark read, archive…) | Notifications propres à l'utilisateur connecté |
| `AttachmentController` | `AttachmentAuthorizer` vérifie la permission métier sur la ressource parente |
| Routes auth (`/login`, reset password…) | Publiques par conception |
| Broadcast channels | Auth + ownership canal |

---

## 4. Routes déjà correctement protégées (aucune modification)

| Zone | Protection |
|------|------------|
| CRUD ressources (products, sales, quotes…) | `checkPermission` dans chaque action |
| `CompanyController` uploads | `company.update` via `uploadAsset()` |
| `Admin/*` | `EnsureUserIsAdmin` + `checkPermission` backups |
| `InventorySessionController` | Permissions inventaire par action |
| `DocumentPreviewController` | `checkAnyPermission` par type de document |
| Exports (customers, suppliers, inventory) | Permissions `export` dédiées |

---

## 5. Routes `/dev/*`

- **Production / testing :** routes **non enregistrées** (`if (app()->environment('local'))`) → 404
- **Local :** enregistrées avec `auth` + `verified` + **`EnsureUserIsAdmin`**
- 27 pages d'expérimentation barcode (lecture seule, outils internes)
- Aucune permission métier inventée (`dev.access` non créée)

---

## 6. Décisions admin-only

| Endpoint | Justification |
|----------|---------------|
| `/dev/*` | Outils de développement internes, accès caméra |
| `/api/notifications/test` | Broadcast de test, pas une action métier |
| `/admin/*` | Gestion utilisateurs, sauvegardes, logs (inchangé) |

---

## 7. Isolation entreprise

MKD-Pro reste **mono-entreprise** (`Company::getInstance()`).

- RBAC et isolation restent **deux couches distinctes**
- Aucune vérification d'isolation supprimée
- Tests : ressource inexistante → 404 ; `company.logo.upload` sans `company.update` → 403

---

## 8. Tests ajoutés

`tests/Feature/Rbac/SensitiveRoutesTest.php` — **19 tests** :

- Produits : upload / generate-sku (autorisé, refusé, legacy `edit`, non-auth)
- BL : validate (permission, refus, admin, gestionnaire preset)
- Dev : non enregistré hors local, 404 en testing
- Notifications : test admin-only
- Isolation : BL inexistant, upload logo sans permission

---

## 9. Risques résiduels (phases suivantes)

| Élément | Phase cible |
|---------|-------------|
| Middleware `CheckPermission` non branché globalement | G+ |
| `inventory.review` → `inventory.reopen` | H |
| Cache RBAC | J |
| Audit log permissions | K |
| Frontend `usePermissions` → `forUser()` | L |
| UI admin RBAC premium | M+ |
| Multi-entreprise / multi-magasin | Documenté, hors V1 |

---

## 10. Fichiers modifiés

- `app/Http/Controllers/Controller.php` — `checkAnyPermission()`
- `app/Http/Controllers/ProductController.php` — upload + SKU
- `routes/web.php` — BL validate, dev admin
- `app/Modules/NotificationCenter/Http/Routes/notifications.php` — test admin

## 11. Fichiers créés

- `tests/Feature/Rbac/SensitiveRoutesTest.php`
- `docs/rbac-sensitive-routes.md`
