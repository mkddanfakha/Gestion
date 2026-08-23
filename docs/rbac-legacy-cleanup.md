# RBAC — Nettoyage legacy contrôlé (Phase N3)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Ne supprime PAS** les lignes `permissions` legacy ni les aliases de lecture.

---

## 1. État avant N3

- Pivots utilisateurs legacy : 0 (local, post-N2)
- Définitions legacy en DB : 11 (conservées)
- Contrôleurs métier : déjà sur `update` / `reopen`
- Aliases `AuthorizationService` / `usePermissions` : actifs
- Grille Users : filtrage frontend `filterCanonicalPermissionGrid` seulement

---

## 2. État après N3

| Zone | Changement |
|------|------------|
| Attribution admin | `AssignablePermissionResolver` — grille + canonicalize IDs |
| User Create/Edit | Payload Inertia sans `*.edit` / `inventory.review` |
| Store/Update user custom | IDs legacy soumis → IDs canoniques |
| Presets | Inchangés (déjà canoniques) |
| Aliases lecture | Conservés |
| Lignes `permissions` legacy | Conservées |
| `canEdit()` | Conservé (`@deprecated`, alias de `canUpdate`) |

---

## 3. Permissions canoniques

```text
*.update
inventory.reopen
```

## 4. Permissions legacy conservées (DB + catalogue)

```text
*.edit (10 modules)
inventory.review
```

## 5. Pourquoi pas encore de suppression physique

- Bases clientes / backups peuvent encore référencer ces noms
- Compatibilité de lecture encore utile jusqu’à validation terrain
- Rollback / dry-run migration N2 toujours disponibles

## 6. Commande de migration

```bash
php artisan rbac:migrate-legacy-permissions --dry-run
php artisan rbac:migrate-legacy-permissions --force
php artisan rbac:migrate-legacy-permissions --status
```

## 7. Rollback

- Migration N2 : transactionnelle (test d’exception)
- N3 ne retire pas les définitions : rollback catalogue = non applicable
- Restaurer un backup DB conserve les lignes legacy

## 8. Critères avant suppression physique (future, hors N4)

1. Toutes les bases migrées (`--status` = 0 pivots legacy)
2. Période de compatibilité validée
3. Tests verts sans dépendre d’attribution legacy (aliases optionnels)
4. Décision produit explicite

**Phase N4 :** suppression physique des lignes `permissions` legacy = **Non effectuée**.

## 9. Risques

| Risque | Mitigation |
|--------|------------|
| Admin enregistre un user avec pivot legacy encore en DB | Aliases lecture + commande migrate + canonicalize à l’update |
| Soumission manuelle d’ID legacy via API | `canonicalizeIds` → nom canonique |
| ID hors catalogue | Ignoré (N4) — jamais écrit |
| Confusion routes Laravel `*.edit` | Documenté : ce ne sont pas des permissions |

## 10. Prochaines étapes

Suppression physique éventuelle des lignes `permissions` legacy — **décision ultérieure documentée**, hors N4.
