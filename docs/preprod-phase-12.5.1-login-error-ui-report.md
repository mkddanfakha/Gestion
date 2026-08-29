# PRE-PROD 12.5.1 — Login Error UI

```text
PRE-PROD 12.5.1 — LOGIN ERROR UI

STATUS: COMPLETE

CAUSE:
InputError.vue used Tailwind classes text-red-600 / dark:text-red-500
that are not present in the production CSS bundle (Bootstrap + theme tokens only).
Also .mkd-auth .form-control in marketing.css overrode Bootstrap is-invalid borders.

FILES MODIFIED:
- resources/js/components/InputError.vue
- resources/js/pages/auth/Login.vue
- resources/css/app.css
- resources/css/marketing.css

FILES CREATED:
- docs/preprod-phase-12.5.1-login-error-ui-report.md

LOGIN LOGIC:
PRESERVED

FORTIFY:
UNCHANGED

ROUTES:
UNCHANGED

BACKEND:
UNCHANGED

DATABASE:
UNCHANGED

ENV:
UNCHANGED

OVH:
UNCHANGED

PROTECTED SITE:
TOUCHED: NO

DARK MODE:
PASS (.input-error / .mkd-auth__error use #fca5a5 in .dark)

RESPONSIVE:
PASS (block wraps; no layout change that causes overflow)

ACCESSIBILITY:
PASS (role=alert on InputError and auth error block; aria-invalid / aria-describedby preserved)

BUILD:
PASS (vite build exit 0)

DEPLOYMENT:
NOT EXECUTED
```

## Détail de la correction

1. **`InputError.vue`** — classe sémantique `.input-error` + `role="alert"` (plus de classes Tailwind absentes du build).
2. **`app.css`** — couleur via `var(--color-danger)` ; dark mode `#fca5a5`.
3. **`marketing.css`** — bordures `is-invalid` forcées (input-group inclus) ; bloc `.mkd-auth__error`.
4. **`Login.vue`** — bandeau d’erreur général affichant le message Inertia/Fortify inchangé.
