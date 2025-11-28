# Configuration rapide de l'envoi d'emails

## Configuration minimale pour tester

### 1. Pour le développement (Mailtrap - GRATUIT)

1. Créez un compte sur https://mailtrap.io/ (gratuit)
2. Récupérez vos identifiants dans "SMTP Settings" > "Inboxes" > "My Inbox"
3. Ajoutez dans votre fichier `.env` :

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=votre-username-mailtrap
MAIL_PASSWORD=votre-password-mailtrap
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Gestion"
```

4. Videz le cache :
```bash
php artisan config:clear
```

5. Testez ! Les emails apparaîtront dans votre inbox Mailtrap.

### 2. Pour la production (Gmail)

1. Activez l'authentification à deux facteurs sur votre compte Gmail
2. Créez un "Mot de passe d'application" : https://myaccount.google.com/apppasswords
3. Ajoutez dans votre fichier `.env` :

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=votre-email@gmail.com
MAIL_PASSWORD=votre-mot-de-passe-application
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=votre-email@gmail.com
MAIL_FROM_NAME="Gestion"
```

4. Videz le cache :
```bash
php artisan config:clear
```

### 3. Vérification rapide

Testez avec cette commande dans tinker :
```bash
php artisan tinker
```

Puis :
```php
Mail::raw('Test', function($m) { $m->to('votre-email@example.com')->subject('Test'); });
```

## Variables d'environnement nécessaires

Assurez-vous que ces variables sont dans votre fichier `.env` :

```env
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```

**⚠️ Important :** Ne commitez JAMAIS votre fichier `.env` dans Git !

