# PRE-PROD — Database safety policy (restore)

Complète `docs/database-safety-policy.md`.

## Restore

1. Cible **toujours explicite** (`--target` / `target_database`).
2. Jamais `DB_DATABASE` / `.env` / config mysql comme cible implicite.
3. Allow-list exacte : `gestion_recovery`, `gestion_test`.
4. `gestion` : restore et DROP interdits depuis l’application.
5. Restore DB **ne restaure jamais** les fichiers applicatifs.
6. File restore = opération séparée, confirmation `FILES_RESTORE`, live overwrite **disabled** en PRE-PROD.
7. Confirmation `RESTORE` nécessaire mais **insuffisante**.
8. `--force` ne contourne rien.
9. Lock restore **global**.
10. Cutover `gestion` : **humain uniquement**.
