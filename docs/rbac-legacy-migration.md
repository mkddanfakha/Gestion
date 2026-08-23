# RBAC — Migration des permissions legacy (Phase N2)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Commande :** `php artisan rbac:migrate-legacy-permissions`

---

## Mappings

| Legacy | Canonique |
|--------|-----------|
| `*.edit` (10 modules) | `*.update` |
| `inventory.review` | `inventory.reopen` |

Source des mappings : `PermissionCatalog::legacyMappings()` (pas de duplication hardcodée hors catalogue).

---

## Stratégie

```text
audit / --status / --dry-run
        ↓
ajouter pivots canoniques manquants
        ↓
vérifier
        ↓
supprimer pivots legacy
```

Ordre strict : **jamais** supprimer le legacy avant d’avoir le canonique.

---

## Sécurité

- Transaction `DB::transaction`
- Idempotente
- Pas de force-sync `RolePresets`
- Pas de suppression des lignes `permissions` legacy
- Admins : rôle inchangé ; pivots legacy nettoyés s’ils existent
- Users custom : seules les permissions legacy concernées sont transformées

---

## Commande

```bash
php artisan rbac:migrate-legacy-permissions --dry-run
php artisan rbac:migrate-legacy-permissions --force
php artisan rbac:migrate-legacy-permissions --status
```

Sans `--force`, une confirmation interactive est demandée.

---

## Audit RBAC

Événement : `rbac.legacy_permissions_migrated`  
via `RbacAuditService::recordLegacyPermissionsMigrated` (acteur console = null).

---

## Compatibilité lecture

Après migration des pivots :

> Legacy permissions remain readable for backward compatibility,  
> but no current user should be assigned one.

`AuthorizationService` / `usePermissions` conservent les aliases temporairement.

---

## Prochaine étape (hors N2 / hors N4)

Suppression physique éventuelle des lignes `permissions` legacy — phase distincte, après validation des bases clientes.

### PHASE N4 — FINAL RBAC HARDENING

- Legacy = lecture/compat uniquement ; écriture = canonique via `AssignablePermissionResolver`.
- Suppression physique des définitions legacy en DB : **Non effectuée en N4.**
- Pivots utilisateur legacy attendus : **0** (commande `--status`).
