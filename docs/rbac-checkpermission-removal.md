# RBAC — Suppression du middleware `CheckPermission` (Phase N1)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Référence audit :** [`rbac-hardening-audit.md`](./rbac-hardening-audit.md)

---

## Avant

Le middleware `App\Http\Middleware\CheckPermission` existait et déléguait déjà à `AuthorizationService::allows()`.

Il n’était :

- enregistré dans **aucune** route ;
- déclaré dans **aucun** alias `bootstrap` / `config` ;
- référencé par **aucun** test ou import applicatif.

Surface morte → dette technique et confusion (« deux chemins d’autorisation »).

---

## Après

Fichier supprimé :

```text
app/Http/Middleware/CheckPermission.php
```

L’autorisation HTTP repose exclusivement sur :

```text
Controller::checkPermission() / checkAnyPermission()
        ↓
AuthorizationService::allows() / any()
```

Facades utilisateur :

```text
User::hasPermission() / hasPermissionByName()
        ↓
AuthorizationService
```

Frontend inchangé :

```text
HandleInertiaRequests → usePermissions() → Vue
```

---

## Raisons

1. Middleware mort (0 route).
2. Duplication inutile du même appel à `AuthorizationService`.
3. Source unique déjà en place via les contrôleurs.
4. Réduction de la dette technique.
5. **Aucun changement fonctionnel attendu.**

---

## Sécurité

La suppression :

- ne retire aucune permission ;
- ne modifie aucun rôle ;
- ne modifie aucun utilisateur ;
- ne change pas le bypass admin ;
- ne change pas les contrôles IDOR / isolation ;
- ne change pas l’inventaire ;
- ne change pas le stock ;
- ne crée aucune migration ;
- n’introduit ni Spatie, ni Redis, ni force-sync.

---

## Recherche post-suppression

Occurrences restantes de `CheckPermission` : **documentation uniquement** (`docs/*`).

Aucune occurrence dans `app/`, `routes/`, `tests/` (hors commentaire éventuel), `bootstrap/`, `config/`.

---

## Tests

`tests/Feature/Rbac/CheckPermissionRemovalTest.php` :

- `products.view` → 200
- sans permission → 403
- admin bypass → 200
- legacy `products.edit` → accès edit produit
- fichier middleware absent + vendeur sales OK
