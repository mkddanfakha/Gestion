# Résolution du conflit Git en production

## Problème
Lors du `git pull origin main`, Git détecte des modifications locales dans `app/Providers/AppServiceProvider.php` qui entrent en conflit avec les modifications distantes.

## Solution 1 : Sauvegarder les modifications locales (recommandé)

Si vous avez des modifications importantes que vous voulez conserver :

```bash
# 1. Sauvegarder les modifications locales
git stash

# 2. Faire le pull
git pull origin main

# 3. Appliquer les modifications sauvegardées (si nécessaire)
git stash pop

# 4. Résoudre les conflits si nécessaire, puis commit
git add app/Providers/AppServiceProvider.php
git commit -m "Merge: Résolution des conflits dans AppServiceProvider"
```

## Solution 2 : Écraser les modifications locales (si elles ne sont pas importantes)

Si les modifications locales ne sont pas importantes et que vous voulez utiliser la version distante :

```bash
# 1. Écraser les modifications locales avec la version distante
git checkout -- app/Providers/AppServiceProvider.php

# 2. Faire le pull
git pull origin main
```

## Solution 3 : Forcer le pull (attention, cela écrasera tout)

⚠️ **ATTENTION** : Cette méthode écrasera toutes les modifications locales non commitées.

```bash
# 1. Réinitialiser complètement à la version distante
git fetch origin
git reset --hard origin/main
```

## Solution 4 : Commit puis merge (si vous voulez garder les deux versions)

Si vous voulez garder vos modifications locales ET les modifications distantes :

```bash
# 1. Commit les modifications locales
git add app/Providers/AppServiceProvider.php
git commit -m "Modifications locales AppServiceProvider"

# 2. Faire le pull (cela créera un merge commit)
git pull origin main

# 3. Résoudre les conflits si nécessaire
# Éditer le fichier pour résoudre les conflits manuellement
# Puis :
git add app/Providers/AppServiceProvider.php
git commit -m "Merge: Résolution des conflits AppServiceProvider"
```

## Recommandation

Pour votre cas spécifique (ajout du fuseau horaire), je recommande la **Solution 2** car :
- Les modifications distantes contiennent déjà la configuration du fuseau horaire
- Les modifications locales sont probablement identiques ou obsolètes
- C'est la méthode la plus simple et la plus sûre

## Commandes à exécuter (Solution 2 recommandée)

```bash
cd /chemin/vers/votre/projet
git checkout -- app/Providers/AppServiceProvider.php
git pull origin main
```

## Vérification après le pull

Après avoir résolu le conflit, vérifiez que le fichier contient bien :

```php
// Configurer le fuseau horaire du Sénégal globalement
date_default_timezone_set('Africa/Dakar');
```

Dans la méthode `boot()` de `AppServiceProvider.php`.

