```text
========================================
PRE-PROD 12.7.2 — BACKUP UX
========================================

STATUS: COMPLETE

UI:
- Page `Admin/Backups/Index.vue` refondue (header, résumé, création, import, liste, empty state, modales).
- Design aligné sur les tokens CSS MKD-Pro (`--color-*`, cartes, badges, PageHeader, IndexPageLayout).
- Titre « Sauvegardes » + sous-titre métier.
- Actions header distinctes : Créer (primary) / Importer (outline).

CREATE BACKUP:
- Cartes explicatives : « Base de données uniquement » vs « Base de données + fichiers ».
- Confirmation modale avant lancement (Annuler / Créer la sauvegarde).
- Anti double-clic via `processing` + désactivation boutons.
- Loading réel via requête Inertia (pas de `sleep()`).
- Chaîne inchangée : `Artisan::call('backup:production' [--only-db])`.

IMPORT:
- Panneau dédié avec zone fichier + distinction claire IMPORTER ≠ RESTAURER.
- Toujours via `BackupImportService` (quarantaine → validation → promote).
- Aucune restauration automatique.
- Messages d’erreur métier FR (mapping controller, logs techniques conservés).

RESTORE:
- Confirmation dédiée (warning), phrase `RESTORE`, cible allow-list.
- Pas de restauration au clic sur une ligne.
- Backend inchangé (DB-only, `gestion` protégée, PathGuard).

DELETE:
- Modale « Supprimer cette sauvegarde ? » + irréversibilité.
- Anti double-clic.

DOWNLOAD:
- Conservé via route `admin.backups.download` + PathGuard.

EMPTY STATE:
- État vide professionnel avec CTA Créer / Importer.

ERROR STATES:
- Flash + SweetAlert messages métier (création / import / suppression / restauration).
- Plus d’exposition brute des exceptions techniques côté UI pour create/import.

NOTIFICATION FIX:
- `onFinish` ne fait plus `Swal.close()` (succès restait immédiatement fermé).
- Succès affiché ~6s (timer + barre) + bandeau flash dismissible.

SUMMARY BLOCK:
- Données réelles uniquement : dernière date, type (peek ZIP), statut (valid/invalid), count, busy (locks).
- Pas d’origine inventée (Automatique/Manuelle non disponible → non affichée).
- Statuts UI utilisés : Réussie, Invalide, En cours (si lock). Pas d’Échec/Importée simulés.

RESPONSIVE:
PASS
- Grille résumé 4 → 2 → 1 colonnes.
- Options création en stack mobile.
- Table desktop + cartes mobile/tablette.
- Modales plein écran utilisables au doigt.

DARK MODE:
PASS
- Tokens thème (`surface`, `border`, `text`, badges via color-mix).

ACCESSIBILITY:
PASS
- Labels, aria-label actions, role=dialog/radiogroup, Escape ferme les modales, alertes live.

TESTS:
PASS — BackupCriticalFixesTest (16)
PASS — BackupStrategyTest + ProductionBackupRunnerTest (26)
(filtre global artisan test bloqué par redeclare createTestProduct hors scope 12.7.2)

BUILD:
PASS — `npm run build` (exit 0)

BACKEND LOGIC:
MODIFIED (affichage uniquement)
- Enrichissement liste : `type`, `status`, `size_bytes`, `summary`, `operations_busy`.
- Messages flash create/import plus métier.
- Aucun changement allow-list restore, scheduler, credentials, migrations.

SECURITY 12.7.1:
PRESERVED
- BackupPathGuard : inchangé, toujours utilisé download/destroy/restore/import paths
- BackupImportService : inchangé, toujours utilisé par import
- BackupArchiveInspector : inchangé
- BackupConcurrencyGuard : inchangé ; busy exposé en lecture seule
- Chaîne `backup:production` : conservée ; pas de nested `runBackup` ; pas de `sleep(`

DATABASE:
UNCHANGED

ENV:
UNCHANGED

SCHEDULER:
UNCHANGED

OVH:
UNCHANGED

PRODUCTION:
UNTOUCHED

DEPLOYMENT:
NOT EXECUTED

FILES:
- resources/js/pages/Admin/Backups/Index.vue (refonte)
- app/Http/Controllers/Admin/BackupController.php (props UI + messages)
- docs/preprod-phase-12.7.2-backup-ux-report.md (ce rapport)

NEXT:
Arrêt après 12.7.2 — attendre validation humaine.
Ne pas commencer 12.7.3+.

========================================
END PRE-PROD 12.7.2
========================================
```
