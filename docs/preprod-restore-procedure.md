# PRE-PROD 3 — Procédure de restauration

## Règle absolue

```text
gestion = READ ONLY pendant PRE-PROD
Jamais restaurer directement dans gestion sans validation humaine + plan de cutover.
```

## Bases

| Base | Rôle | Restore |
|------|------|---------|
| `gestion` | Production / témoin | **BLOCKED** |
| `gestion_recovery` | Test restore | ALLOWED (+ phrase RESTORE) |
| `gestion_test` | Tests | ALLOWED |

## Procédure d’urgence — INCIDENT DATABASE

1. **STOP** toute écriture destructive si nécessaire
2. **NE PAS** lancer `migrate:fresh` / `db:wipe` / `migrate:refresh`
3. **NE PAS** restaurer directement dans `gestion`
4. Identifier le dernier backup **vérifié** : `php artisan backup:status` puis `php artisan backup:verify {path}`
5. Créer `gestion_recovery` (**humain**, DDL) si absent
6. Restaurer **uniquement** vers `gestion_recovery` (UI Admin ou flux contrôlé) avec confirmation `RESTORE`
7. Vérifier intégrité (tables, counts, FK)
8. Comparer données vs manifeste / attentes métier
9. Validation humaine
10. Planifier cutover vers `gestion` (hors automatisation PRE-PROD)

## Flux normal de test restore

```text
backup vérifié
   ↓
gestion_recovery
   ↓
import SQL
   ↓
integrity checks
   ↓
rapport
```

## Locks

- Une seule restauration active (`BackupConcurrencyGuard`, TTL **30 min**)
- Une seule sauvegarde active (TTL **2 h**)
- Contournement UI impossible (backend)
- Expiration TTL = récupération d’un verrou périmé (stale lock)

## Avant opération critique

```text
BACKUP → VERIFY → OPERATION
```

jamais l’inverse.

## UI

Admin → Backups → restaurer DB uniquement si :

- phrase `RESTORE`
- case à cocher
- cible allow-list
- lock libre
