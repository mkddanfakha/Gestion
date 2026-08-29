# PRE-PROD — Data Loss Closure

**Date de clôture :** 2026-08-26  
**Phase :** PRE-PROD 9.4  
**Décision :** arrêt volontaire de la recherche active des données d’août 2026

---

## Incident — chronologie

| Date (approx.) | Événement |
|----------------|-----------|
| **2026-08-23** | Première perte : `scripts/measure-rbac-queries.php` → `Artisan::call('migrate:fresh', ['--force' => true])` sur `.env` local (`DB_DATABASE=gestion`) |
| **2026-08-23 → 25** | Durcissement safety, comptes least-privilege, restore drill sur `gestion_recovery` (snapshot nov. 2025) |
| **2026-08-25** | PRE-PROD 9.3.3 : probe `DROP DATABASE gestion` via `gestion_migration` (**succès** — fail design MySQL `DROP ON db.*`) → base `gestion` absente |
| **2026-08-26** | PRE-PROD 9.3.4–9.3.5 : validation recovery + recherche forensique |
| **2026-08-26** | PRE-PROD 9.4 : **arrêt recherche recovery** — continuation clean start |

## Cause racine

1. Script temporaire hors tests isolés bootstrappant Laravel + commande destructive.
2. Absence initiale de garde-fous sur `migrate:fresh` / `Artisan::call`.
3. Privilège MySQL `DROP ON gestion.*` permettant `DROP DATABASE gestion` (confirmé 9.3.3).

## Conclusion de récupération (9.3.5)

- Aucun dump MySQL **complet** des données métier d’**août 2026** retrouvé.
- `gestion_recovery` = drill / snapshot **novembre 2025** — **≠** août 2026.
- Exports août 2026 (clients, inventaire `INV2608002`) = **partiels**, hors MySQL.
- Binlogs : `log_bin=OFF` — PITR impossible.
- Reconstruction fiable et complète d’août 2026 : **non possible** avec les sources actuelles.

## Décision humaine

> ARRÊT DE LA RECHERCHE ACTIVE DES ANCIENNES DONNÉES.

Le projet ne consacre plus de temps à rechercher ou reconstruire automatiquement les données d’août 2026.

## Preuves / sauvegardes conservées (NE PAS SUPPRIMER)

- Base MySQL `gestion_recovery` (inchangée)
- ZIP Spatie `storage/app/private/Gestion/2025-11-28-*.zip` (+ copies `Downloads/test/`)
- Dumps SQL : `Downloads/gestion.sql`, `Downloads/gestion (1).sql`, `Documents/mes-db/gestion.sql`, dump restore-temp
- Exports août 2026 : `clients_2026-08-*.xlsx`, `inventaire_INV2608002_*`
- Rapports : `docs/preprod-phase-9.3.*`, `docs/preprod-phase-9.3.5-forensic-recovery-report.md`, etc.

**Ces sources ne doivent PAS être restaurées automatiquement dans `gestion`.**

## Mesures de protection (état au début 9.4)

- Guards applicatifs : `DatabaseSafetyGuard`, `DestructiveCommandGuard`, `DatabaseAccountGuard`, `PrivilegedCommandGuard`
- Comptes : `gestion_app` (CRUD), `gestion_backup` (lecture), `gestion_restore` (recovery/test only), `gestion_migration` (**DROP encore présent — à révoquer**)
- Runtime `.env` : `DB_USERNAME=gestion_app`, `DB_DATABASE=gestion`
- Règle Cursor : `.cursor/rules/database-safety-gestion.mdc`
- Tests Pest infrastructure (safety / account separation)

## Conclusion

> Recovery of the complete August 2026 business dataset is abandoned for now. Historical evidence is preserved. The project proceeds with a clean database and hardened recovery architecture.
