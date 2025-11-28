# Guide pour résoudre l'erreur "Can't create TCP/IP socket (10106)"

## Problème
MySQL n'accepte pas les connexions TCP/IP, ce qui empêche `mysqldump` de fonctionner.

## Solution : Modifier my.ini dans WAMP

### Étape 1 : Ouvrir my.ini
1. Cliquez sur l'icône **WAMP** dans la barre des tâches Windows
2. Allez dans **MySQL** > **my.ini**
3. Le fichier s'ouvrira dans votre éditeur par défaut

### Étape 2 : Trouver la section [mysqld]
1. Dans le fichier `my.ini`, cherchez la section `[mysqld]`
2. Elle se trouve généralement vers les lignes 50-60
3. Vous devriez voir quelque chose comme :
   ```ini
   [mysqld]
   port=3306
   # ... autres paramètres ...
   ```

### Étape 3 : Ajouter ou modifier bind-address
1. Dans la section `[mysqld]`, cherchez la ligne `bind-address`
2. **Si elle existe** : modifiez-la pour :
   ```ini
   bind-address = 0.0.0.0
   ```
3. **Si elle n'existe pas** : ajoutez-la après `port=3306` :
   ```ini
   [mysqld]
   port=3306
   bind-address = 0.0.0.0
   # ... autres paramètres ...
   ```

### Étape 4 : Sauvegarder et redémarrer
1. **Sauvegardez** le fichier `my.ini`
2. **Redémarrez MySQL** :
   - Cliquez sur l'icône WAMP
   - Allez dans **MySQL** > **Redémarrer le service**
   - OU : Cliquez droit sur WAMP > **Redémarrer tous les services**
3. Attendez que l'icône WAMP redevienne **verte**

### Étape 5 : Vérifier
1. Exécutez la commande de test :
   ```bash
   php artisan mysql:test-connection
   ```
2. Si le test réussit, essayez de créer une sauvegarde depuis l'interface web

## Alternative : Utiliser 127.0.0.1 au lieu de localhost

Si vous ne pouvez pas modifier `my.ini`, assurez-vous que votre fichier `.env` utilise `127.0.0.1` et non `localhost` :

```env
DB_HOST=127.0.0.1
DB_PORT=3306
```

Puis exécutez :
```bash
php artisan config:clear
```

## Vérification manuelle de mysqldump

Pour tester manuellement si mysqldump fonctionne :

```bash
C:/wamp64/bin/mysql/mysql9.1.0/bin/mysqldump.exe --host=127.0.0.1 --port=3306 --user=root --databases gestion --no-data
```

Si cette commande fonctionne, le problème vient de la configuration de Spatie Backup.
Si cette commande échoue avec la même erreur, MySQL n'accepte pas les connexions TCP/IP et vous devez modifier `my.ini`.

