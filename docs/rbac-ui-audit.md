# RBAC UI — Audit Phase M0 (avant implémentation)

**Date :** 2026-08-23  
**Statut :** Audit terminé — GO implémentation Phase M  
**Périmètre :** interface d’administration des rôles & permissions uniquement

---

## 1. Interface existante

| Zone | État |
|------|------|
| Admin Users Index/Create/Edit | CRUD utilisateurs + grille permissions (Phase L : canonique, presets aperçu) |
| Page « Rôles & permissions » | **Absente** |
| Navigation admin | Utilisateurs, Sauvegardes, Journal, Notifications — pas d’entrée RBAC dédiée |
| Journal d’activité | Complet (`ActivityLogs`) ; filtre module possible (`RBAC` existe depuis Phase K) |
| Spatie / table roles | Absent (volontaire) |

---

## 2. Composants réutilisables

| Composant | Usage Phase M |
|-----------|---------------|
| `IndexPageLayout` + `PageHeader` | Layout pages admin |
| Bootstrap `card` / tokens `--color-*` | Cartes rôles, dark mode |
| `NotificationDrawer` | Pattern drawer mobile (référence UX, pas réutilisation directe) |
| `ui/card`, `ui/badge` | Optionnels ; Admin Users utilise surtout Bootstrap |
| `rbacPermissions.ts` | Filtrage legacy, labels actions |
| `usePermissions` | UX seulement (`isAdmin` pour nav) |

---

## 3. Données disponibles

### PHP

- `PermissionCatalog::all()` — name, module, action, label, description, legacy, canonical  
- `RolePresets::for(role)` / `permissionIds` — listes de permissions par preset  
- `User::ROLE_*` — admin, vendeur, gestionnaire, user  
- `ActivityLog` module `RBAC` — événements Phase K  

### Inertia actuel

- `auth.user.permissions` + `role` (pas de catalogue global partagé)  
- Admin Users : `permissionsByResource` depuis table `permissions`  

### Manquant pour l’UI premium

- Agrégat catalogue + presets + compteurs users par rôle  
- Payload matrice rôles × permissions  
- Journal RBAC récent (facile via query `module = RBAC`)  

→ **Presenter backend dédié** (pas de duplication métier hors lecture).

---

## 4. Permissions exposées / affichage

| Règle | UI |
|-------|-----|
| `*.update` canonique | Afficher « Modifier » |
| `*.edit` legacy | Masquer si canonique présent |
| `inventory.reopen` | Afficher « Rouvrir » |
| `inventory.review` | Section legacy / masqué |
| Admin bypass | « Accès complet », pas 74 cases cochées |
| Gestionnaire | Aucun `sales.*` |

---

## 5. Limites actuelles

1. Permissions utilisateur = grille brute (amélioration partielle Phase L).  
2. Presets encore listés en dur côté Vue pour aperçu (alignés PHP).  
3. Pas de vue comparative entre rôles.  
4. Journal RBAC non mis en avant (noyé dans journal global).  
5. Pas de recherche/filtre permission sur Create/Edit user.  

---

## 6. Propositions UI

1. Route `GET /admin/roles-permissions` → `Admin/RolesPermissions/Index.vue`  
2. Nav : **Rôles & permissions** (admin only)  
3. Cartes rôles + drawer détail + matrice + journal RBAC récent  
4. Composant `RbacPermissionGroup.vue` réutilisé sur Users Create/Edit  
5. Presenter `App\Auth\RbacUiPresenter` (lecture seule catalogue/presets/counts/audit)  

---

## 7. Risques de régression

| Risque | Mitigation |
|--------|------------|
| N+1 users | Un `groupBy('role')` |
| Duplication RolePresets | Lire `RolePresets` / `PermissionCatalog` uniquement |
| Contournement last-admin | Aucune nouvelle mutation hors UserController |
| Inventaire / stock | Aucun fichier stock touché |
| Dark mode illisible | Tokens `--color-*` + classes Bootstrap existantes |

---

## 8. Hors périmètre Phase M

- Table `roles`, Spatie, force-sync  
- Modification `AuthorizationService` / règles métier  
- Refonte complète Users Index  
- Phase N  

---

*Fin audit M0 — implémentation autorisée.*
