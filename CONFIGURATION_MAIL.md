# Configuration de l'envoi d'emails

## Problème actuel

Par défaut, Laravel utilise le driver `log` qui enregistre les emails dans les fichiers de logs au lieu de les envoyer réellement. C'est pourquoi vous voyez un message de confirmation mais aucun email n'est reçu.

## Solutions

### Option 1 : Configuration SMTP (Recommandé pour la production)

Pour envoyer des emails via un serveur SMTP (Gmail, Outlook, serveur dédié, etc.) :

1. **Modifiez votre fichier `.env`** avec les paramètres suivants :

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=votre-email@gmail.com
MAIL_PASSWORD=votre-mot-de-passe-application
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=votre-email@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

**Pour Gmail spécifiquement :**
- Vous devez activer "Accès aux applications moins sécurisées" OU utiliser un "Mot de passe d'application"
- Pour créer un mot de passe d'application : https://myaccount.google.com/apppasswords

**Pour Outlook/Hotmail :**
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp-mail.outlook.com
MAIL_PORT=587
MAIL_USERNAME=votre-email@outlook.com
MAIL_PASSWORD=votre-mot-de-passe
MAIL_ENCRYPTION=tls
```

**Pour un serveur SMTP personnalisé :**
```env
MAIL_MAILER=smtp
MAIL_HOST=votre-serveur-smtp.com
MAIL_PORT=587
MAIL_USERNAME=votre-username
MAIL_PASSWORD=votre-mot-de-passe
MAIL_ENCRYPTION=tls
# ou MAIL_ENCRYPTION=ssl pour le port 465
```

### Option 2 : Mailtrap (Recommandé pour le développement/test)

Mailtrap est un service qui capture les emails en développement sans les envoyer réellement :

1. **Créez un compte gratuit sur** : https://mailtrap.io/

2. **Récupérez vos identifiants SMTP** depuis votre inbox Mailtrap

3. **Modifiez votre fichier `.env`** :

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=votre-username-mailtrap
MAIL_PASSWORD=votre-password-mailtrap
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Option 3 : Services tiers (Production)

#### Mailgun
```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=votre-domaine.mailgun.org
MAILGUN_SECRET=votre-secret-key
MAILGUN_ENDPOINT=api.mailgun.net
```

#### Postmark
```env
MAIL_MAILER=postmark
POSTMARK_TOKEN=votre-token-postmark
```

#### Amazon SES
```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=votre-access-key
AWS_SECRET_ACCESS_KEY=votre-secret-key
AWS_DEFAULT_REGION=us-east-1
```

## Après la configuration

1. **Videz le cache de configuration** :
```bash
php artisan config:clear
```

2. **Testez l'envoi d'email** :
```bash
php artisan tinker
```
Puis dans tinker :
```php
Mail::raw('Test email', function ($message) {
    $message->to('votre-email@example.com')
            ->subject('Test');
});
```

3. **Vérifiez les logs** en cas d'erreur :
```bash
tail -f storage/logs/laravel.log
```

## Vérification de la configuration

Pour vérifier que votre configuration est correcte, vous pouvez créer une route de test temporaire :

```php
Route::get('/test-email', function () {
    try {
        Mail::raw('Email de test', function ($message) {
            $message->to('votre-email@example.com')
                    ->subject('Test d\'envoi d\'email');
        });
        return 'Email envoyé avec succès !';
    } catch (\Exception $e) {
        return 'Erreur : ' . $e->getMessage();
    }
});
```

**⚠️ Important :** Supprimez cette route après les tests pour des raisons de sécurité.

## Dépannage

### Erreur "Connection timeout"
- Vérifiez que le port et l'hôte sont corrects
- Vérifiez votre pare-feu
- Essayez avec `MAIL_ENCRYPTION=ssl` et port `465`

### Erreur "Authentication failed"
- Vérifiez vos identifiants
- Pour Gmail, utilisez un mot de passe d'application
- Vérifiez que l'authentification à deux facteurs est configurée correctement

### Les emails partent mais n'arrivent pas
- Vérifiez le dossier spam
- Vérifiez que `MAIL_FROM_ADDRESS` est une adresse valide
- Vérifiez les logs : `storage/logs/laravel.log`

