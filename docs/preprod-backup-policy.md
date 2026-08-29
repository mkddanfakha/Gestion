# DATABASE RECOVERY POLICY — MKD-Pro

## Objectifs

| Métrique | Objectif |
|----------|----------|
| RPO | ≤ 24 heures (idéal ≤ 12 h) |
| RTO | ≤ 2–4 heures vers environnement recovery |

Ces objectifs ne sont **pas** déclarés atteints tant qu’un restore test réel n’a pas été validé.

## Fréquence

- Backup automatique quotidien 02:00
- Cleanup 03:00 / monitor 04:00
- Backup manuel avant migration majeure / maintenance / phase Cursor critique

## Rétention

- 30 jours (all + daily)
- 12 semaines (weekly)
- 12 mois (monthly)

## Offsite

Obligatoire pour statut **PROTECTED**.

État actuel : **NOT CONFIGURED** → **PARTIALLY PROTECTED**.

## Responsable

À désigner (opérateur MKD-Pro / admin système).

En cas d’absence développeur : suivre `docs/preprod-restore-procedure.md`.

## Backup avant opération critique

Policy :

```text
1. php artisan db:safety-check
2. php artisan backup:run   (humain / cron dédié)
3. php artisan backup:verify {latest}
4. Opération uniquement si verdict acceptable (pas SCHEMA_ONLY, pas FAILED)
```

## Safety check avant backup

```text
DATABASE SAFETY CHECK
Operation: BACKUP ONLY
Target: gestion (lecture dump — autorisé)
Status: SAFE
```

Interdit : enchaîner backup + opération destructive non contrôlée.

## Sécurité des archives

- Stockage sous `storage/app/private` (disque `serve` = false)
- Accès download via routes auth + permission `backups.download`
- Ne jamais exposer via `/storage/...` public
- Exclure `.env` des futurs backups
- Traiter les ZIP comme données personnelles / commerciales sensibles

## Restore test périodique

Objectif : **1× / mois minimum**

```text
backup → restore gestion_recovery → integrity → report
```

Sans ce test : backup = **CREATED** au mieux, pas **RESTORE TESTED**.
