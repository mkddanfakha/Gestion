# PRE-PROD 4 — Final Safety Gate

## Décision

```text
PRODUCTION STATUS: PRODUCTION READY WITH CONDITIONS
```

### Conditions obligatoires avant vraie production métier

1. **OFFSITE BACKUP = REQUIRED BEFORE REAL PRODUCTION**
2. Restore drill MySQL réussi vers `gestion_recovery` (jamais `gestion`)
3. Séparer restore **DB-only** du restore fichiers applicatifs
4. Alerting backup opérationnel (échec / trop ancien)
5. Backup quotidien **vérifié** (pas seulement schedule documenté)

Sans ces conditions : développement local/staging contrôlé uniquement.

---

## Ce qui est PASS (prouvé)

- Kill switch `migrate:fresh|refresh|reset` + `db:wipe` sur nom exact `gestion`
- Indépendant de `APP_ENV` (local / testing / production)
- `Artisan::call` bloqué via wrappers container
- Restore / DROP `gestion` bloqués côté application
- PHPUnit isolé SQLite `:memory:`
- Locks backup/restore
- Rétention 30/12/12 configurée
- `gestion` inchangée pendant PRE-PROD 0–4 (counts post-incident stables)

## Ce qui est FAIL / NOT VERIFIED pour production pleine

| Item | État |
|------|------|
| Offsite | NOT CONFIGURED |
| Restore drill | NOT TESTED |
| RPO | DOCUMENTED, NOT VERIFIED |
| RTO | DOCUMENTED, NOT VERIFIED |
| Monitoring alerting | PARTIAL |

---

## Matrice d’usage

| Environnement | Autorisé ? | Notes |
|---------------|------------|-------|
| Development | OUI | Toujours SQLite/test pour destructive ; jamais script bootstrap sur `.env` métier |
| Staging | OUI AVEC CONDITIONS | Base ≠ `gestion` production ; pas de restore fichiers live |
| Production | NON (plein) | Attendre offsite + drill + restore DB-only |

---

## Bypass résiduels classés

| Vecteur | Sévérité | Note |
|---------|----------|------|
| `mysql` CLI / phpMyAdmin DROP | CRITICAL (hors app) | Hors périmètre Laravel |
| `composer setup` → migrate --force | WARNING | Pas wipe, mais écrit schéma |
| `db:seed` sur gestion | WARNING | Non bloqué |
| HTTP restore + allow-list + overwrite files | CRITICAL | Ne pas utiliser sur machine prod live |
| Changer `DB_PROTECTED_DATABASES` | CRITICAL | Config humaine |
| Ancien ZIP contenant secrets | WARNING | Exclusion `.env` pour futurs backups |

---

## Procédure d’urgence (rappel)

Voir `docs/preprod-restore-procedure.md`.

Cutover automatique : **interdit**.

---

## Signature audit

- Audit code : PRE-PROD 4
- Confiance kill switch : **HIGH** (code + tests)
- Confiance récupération complète : **LOW–MEDIUM** (pas d’offsite, pas de drill)
