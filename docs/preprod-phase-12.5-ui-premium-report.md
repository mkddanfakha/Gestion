# PRE-PROD 12.5 — UI Premium Report (Accueil · Connexion · Mot de passe oublié)

```text
========================================
PRE-PROD 12.5 — UI PREMIUM REPORT
========================================

STATUS: COMPLETE (local only)

HOME:
- fichier modifié: resources/js/pages/Welcome.vue
- améliorations: hero premium MKD-Pro, features réelles, confiance, CTA, footer sobre, responsive

LOGIN:
- fichier modifié: resources/js/pages/auth/Login.vue
- améliorations: carte auth, toggle mot de passe, états loading/erreur/focus, wording

FORGOT PASSWORD:
- fichier modifié: resources/js/pages/auth/ForgotPassword.vue
- améliorations: wording clair, succès élégant, CTA « Envoyer le lien », retour login

COMPONENTS:
- créés: resources/css/marketing.css
- modifiés: AuthSimpleLayout.vue, InputError.vue (prop id), app.css (import)

CSS:
- fichiers modifiés: resources/css/app.css, resources/css/marketing.css (nouveau)

ROUTES:
- modifiées: NO

BACKEND:
- modifié: NO

DATABASE:
- modified: NO

ENV:
- modified: NO

OVH:
- modified: NO

PROTECTED SITE:
- /home/mkdproq/www/mkd-pro
- TOUCHED: NO

BUILD:
- PASS (vite build ~1m51s)

RESPONSIVE:
- PASS (CSS grid breakpoints desktop/tablette/mobile)

DARK MODE:
- PASS (tokens .dark + surfaces marketing adaptées)

ACCESSIBILITY:
- PASS (labels, focus, aria-invalid/describedby, toggle password, prefers-reduced-motion)

AUTHENTICATION LOGIC:
- PRESERVED (Form Inertia store.form())

PASSWORD RESET LOGIC:
- PRESERVED (PasswordResetLinkController.store.form())

DEPLOYMENT:
- NOT EXECUTED

========================================
END PRE-PROD 12.5
========================================
```
