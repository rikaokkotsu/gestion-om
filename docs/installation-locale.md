# Installer Gestion OM sur son ordinateur, sans Docker

Ce guide explique, pas à pas, comment faire fonctionner Gestion OM sur un ordinateur personnel pour l'essayer ou s'en servir seul. Il ne demande aucune connaissance en informatique : il suffit de suivre les étapes dans l'ordre et de copier les commandes indiquées.

Pour installer l'application sur un serveur partagé par plusieurs contrôleurs, suivez plutôt [exploitation.md](exploitation.md).

**Durée** : environ une heure la première fois, surtout en téléchargements. Ensuite, lancer l'application prend une minute.

**Il vous faut** :

- un ordinateur sous Windows 10 ou 11 (pour macOS ou Linux, voir la [fin du guide](#installer-sur-macos-ou-linux)) ;
- les droits d'installer des logiciels sur cet ordinateur ;
- une connexion Internet ;
- l'accès au projet sur GitHub (https://github.com/rikaokkotsu/gestion-om).

## Avant de commencer : trois mots de vocabulaire

- **Une commande** est une ligne de texte que l'on tape (ou colle) dans une fenêtre spéciale, puis que l'on valide avec la touche **Entrée**. Dans ce guide, chaque commande est dans un cadre gris :

  ```
  php -v
  ```

- **La fenêtre de commandes** est une fenêtre noire où l'on tape ces commandes. Sous Windows, on utilise l'**Invite de commandes**. L'étape 6 explique comment l'ouvrir au bon endroit.
- **Le dossier du projet** est le dossier qui contient les fichiers de Gestion OM. Vous le reconnaissez à la présence des fichiers `artisan` et `composer.json`.

Conseils pour toutes les étapes :

- **Copiez-collez les commandes** plutôt que de les retaper : un seul caractère de travers suffit à tout bloquer. Dans l'Invite de commandes, un **clic droit** colle le texte copié.
- **Une commande à la fois**. Attendez qu'elle soit terminée avant la suivante : c'est fini quand la ligne qui se termine par `>` réapparaît et que le curseur clignote de nouveau.
- Des lignes de texte qui défilent, même en anglais, sont normales. Ne vous inquiétez que si le mot **error** ou **erreur** apparaît à la fin : la section [En cas de problème](#en-cas-de-problème) donne la solution des erreurs les plus fréquentes.

## Étape 1 : installer PHP et Composer (avec Laravel Herd)

PHP est le langage dans lequel le serveur de Gestion OM est écrit ; Composer télécharge les composants dont il a besoin. Le logiciel gratuit **Laravel Herd** installe les deux en une fois.

1. Allez sur https://herd.laravel.com et cliquez sur **Download for Windows**.
2. Ouvrez le fichier téléchargé et acceptez toutes les propositions (**Next**, puis **Install**). Windows peut demander une autorisation : répondez **Oui**.
3. À la fin, Herd s'ouvre. Vous pouvez le fermer : la version gratuite suffit, il n'y a rien à acheter ni à configurer.

**Vérification.** Ouvrez le menu Démarrer, tapez `cmd` et ouvrez **Invite de commandes**. Tapez :

```
php -v
```

Vous devez voir une ligne qui commence par `PHP 8.3`, `PHP 8.4` ou un numéro plus élevé. Tapez ensuite :

```
composer -V
```

Vous devez voir `Composer version 2…`. Enfin, vérifiez que PHP sait parler à PostgreSQL :

```
php -m | findstr pgsql
```

Vous devez voir deux lignes : `pdo_pgsql` et `pgsql`.

> **Si `php -v` affiche `PHP 8.2` ou un numéro plus petit**, ou si un message mentionne `C:\xampp` ou `C:\wamp` : un ancien PHP, souvent celui de **XAMPP** ou de **WAMP**, passe avant celui de Herd. Gestion OM ne fonctionne pas avec lui. Sans rien désinstaller, retirez-le de la liste des dossiers où Windows cherche les programmes (le « Path ») :
>
> 1. Menu Démarrer, tapez `variables d'environnement` et ouvrez **Modifier les variables d'environnement système**.
> 2. Cliquez sur **Variables d'environnement…**.
> 3. Dans la liste du haut, cliquez sur la ligne **Path**, puis **Modifier…**. Si une ligne comme `C:\xampp\php` (ou un dossier `php` sous `C:\wamp64`) y figure, sélectionnez-la, cliquez sur **Supprimer**, puis **OK**.
> 4. Faites de même pour la ligne **Path** de la liste du bas.
> 5. Validez par **OK** jusqu'à fermer toutes les fenêtres, fermez **toutes** les fenêtres de commandes, puis rouvrez-en une et refaites la vérification.
>
> **Si `php` « n'est pas reconnu en tant que commande interne »** : fermez la fenêtre de commandes et rouvrez-la. Si le message persiste, redémarrez l'ordinateur.
>
> **Si la dernière commande n'affiche rien** : tapez `php --ini` ; la ligne « Loaded Configuration File » donne l'emplacement du fichier `php.ini`. Ouvrez ce fichier avec le Bloc-notes, cherchez `;extension=pdo_pgsql` et effacez le point-virgule du début ; faites de même pour `;extension=pgsql`. Enregistrez, fermez la fenêtre de commandes, rouvrez-la et refaites la vérification.

## Étape 2 : installer Node.js

Node.js sert à préparer l'interface (les pages que vous verrez dans le navigateur).

Dans la fenêtre de commandes, tapez d'abord :

```
node -v
```

Si vous voyez `v22` ou un numéro plus élevé (`v24`…), Node.js est déjà installé : passez à l'étape 3. Sinon :

1. Allez sur https://nodejs.org et téléchargez la version marquée **LTS**.
2. Ouvrez le fichier téléchargé et acceptez les choix proposés (**Next** jusqu'à **Install**, puis **Finish**). Si une case propose d'installer des « outils pour modules natifs » (*Tools for Native Modules*), laissez-la décochée.
3. Fermez la fenêtre de commandes, rouvrez-la et tapez `node -v` : vous devez voir `v22` ou plus.

## Étape 3 : installer PostgreSQL

PostgreSQL est la base de données : c'est là que sont rangés les employés, les OM, les anomalies…

1. Allez sur https://www.postgresql.org/download/windows/ et cliquez sur **Download the installer**.
2. Dans le tableau, choisissez la version **16** (ou plus récente) dans la colonne **Windows x86-64** et téléchargez-la.
3. Ouvrez le fichier téléchargé et avancez avec **Next** en gardant les choix proposés, sauf aux écrans suivants :
   - **Password** : choisissez un mot de passe pour l'administrateur de la base (l'utilisateur `postgres`), tapez-le deux fois, et **notez-le** sur papier : il servira à l'étape 4.
   - **Port** : laissez `5432`.
   - Dernier écran : **décochez** « Launch Stack Builder at exit », puis cliquez sur **Finish**.

PostgreSQL démarre désormais tout seul à chaque allumage de l'ordinateur.

## Étape 4 : créer la base de données de Gestion OM

1. Ouvrez le menu Démarrer, tapez `psql` et ouvrez **SQL Shell (psql)**. Une fenêtre noire s'ouvre et pose quatre questions (`Server`, `Database`, `Port`, `Username`) : appuyez simplement sur **Entrée** à chacune pour garder la réponse proposée entre crochets.
2. À la question `Password for user postgres:`, tapez le mot de passe noté à l'étape 3, puis **Entrée**. **Rien ne s'affiche pendant que vous tapez** : c'est normal, c'est une protection.
3. La ligne `postgres=#` apparaît. Copiez-collez la commande suivante, puis **Entrée** :

   ```sql
   CREATE ROLE gestion_om LOGIN PASSWORD 'secret' CREATEDB;
   ```

   La fenêtre répond `CREATE ROLE`.

4. Puis celle-ci, et **Entrée** :

   ```sql
   CREATE DATABASE gestion_om OWNER gestion_om ENCODING 'UTF8' TEMPLATE template0;
   ```

   La fenêtre répond `CREATE DATABASE`.

5. Tapez `\q` puis **Entrée** pour fermer la fenêtre.

> Le mot de passe `secret` est celui que l'application utilise par défaut sur un ordinateur personnel. Il ne doit **jamais** être utilisé sur un serveur.

## Étape 5 : télécharger Gestion OM

1. Ouvrez https://github.com/rikaokkotsu/gestion-om dans votre navigateur (connectez-vous à GitHub si on vous le demande).
2. Cliquez sur le bouton vert **Code**, puis sur **Download ZIP**.
3. Ouvrez votre dossier **Téléchargements**, faites un clic droit sur le fichier `gestion-om-main.zip` et choisissez **Extraire tout…**. Comme destination, choisissez votre dossier **Documents**, puis cliquez sur **Extraire**.
4. Ouvrez le dossier extrait. S'il ne contient qu'un seul dossier `gestion-om-main`, entrez dedans. Vous êtes dans **le dossier du projet** quand vous voyez les fichiers `artisan` et `composer.json`.

## Étape 6 : ouvrir une fenêtre de commandes dans le dossier du projet

C'est le geste à refaire à chaque fois que vous voulez lancer Gestion OM :

1. Dans l'Explorateur de fichiers, ouvrez le dossier du projet (celui qui contient `artisan`).
2. Cliquez **dans la barre d'adresse** en haut de la fenêtre (là où est écrit le chemin du dossier) : le texte se sélectionne.
3. Tapez `cmd` à la place, puis **Entrée**.

Une Invite de commandes s'ouvre, déjà placée dans le bon dossier : la ligne se termine par `…\gestion-om-main>`.

> Utilisez bien l'**Invite de commandes** (`cmd`) et non PowerShell : avec PowerShell, la commande `npm` est souvent bloquée par Windows.

## Étape 7 : installer l'application (une seule fois)

Dans la fenêtre ouverte à l'étape 6, lancez les commandes suivantes **une par une**, en attendant la fin de chacune.

1. Télécharger les composants du serveur (quelques minutes) :

   ```
   composer install
   ```

   Un dossier `vendor` doit être apparu dans le dossier du projet. **S'il n'y est pas, ne continuez pas** : relisez les dernières lignes de la fenêtre et cherchez-les dans [En cas de problème](#en-cas-de-problème).

2. Télécharger les composants de l'interface (quelques minutes) :

   ```
   npm install
   ```

3. Créer le fichier de réglages de l'application :

   ```
   copy .env.example .env
   ```

   La fenêtre répond `1 fichier(s) copié(s).`

4. Créer la clé secrète de l'application :

   ```
   php artisan key:generate
   ```

   La fenêtre répond `Application key set successfully.`

5. Créer les tables de la base, avec des données d'exemple pour découvrir l'application :

   ```
   php artisan migrate --seed
   ```

   Des lignes `DONE` défilent, puis `Seeding database.`

6. Préparer l'interface :

   ```
   npm run build
   ```

   La dernière ligne indique `built in …s`.

L'installation est terminée.

> **Données d'exemple ou vraies données ?** La commande de l'étape 5 crée un compte de démonstration, quelques lieux et quatre employés fictifs, pratiques pour découvrir l'application. Pour commencer directement avec vos vraies données, remplacez-la par ces deux commandes :
>
> ```
> php artisan migrate
> php artisan om:premier-compte
> ```
>
> La seconde demande votre nom, votre adresse e-mail et un mot de passe (10 caractères minimum, avec au moins une lettre et un chiffre ; il ne s'affiche pas pendant la frappe). Ce sera le premier compte contrôleur ; les autres se créent ensuite depuis l'application, page **Comptes**.

## Étape 8 : lancer Gestion OM

À chaque fois que vous voulez utiliser l'application :

1. Ouvrez une fenêtre de commandes dans le dossier du projet (étape 6).
2. Tapez :

   ```
   php artisan serve
   ```

   La fenêtre affiche `Server running on [http://127.0.0.1:8000]` : vérifiez que le numéro de la fin est bien `8000`. **Laissez cette fenêtre ouverte** tant que vous utilisez l'application ; vous pouvez la réduire.

3. Ouvrez votre navigateur (Chrome, Edge, Firefox…) à l'adresse **http://localhost:8000**.
4. Connectez-vous. Avec les données d'exemple : adresse `controleur@example.com`, mot de passe `motdepasse1`. Sinon, avec le compte créé par `om:premier-compte`.

Le [guide d'utilisation](guide-utilisation.md) explique ensuite chaque écran.

**Pour arrêter l'application**, revenez dans la fenêtre de commandes et appuyez sur **Ctrl + C** (ou fermez simplement la fenêtre). Si la fenêtre demande « Terminer le programme de commandes (O/N) ? », tapez `O` puis **Entrée**. Vos données restent enregistrées dans la base : vous les retrouverez au prochain lancement.

### Pour utiliser le contrôle par lot

Le bouton **Lancer le contrôle** d'un OM fonctionne tout de suite. Le **contrôle par lot** (page Anomalies, onglet « Contrôle par lot ») a besoin d'une seconde fenêtre : ouvrez une autre fenêtre de commandes dans le dossier du projet (étape 6) et tapez :

```
php artisan queue:work
```

Laissez-la ouverte à côté de la première. Sans elle, un contrôle par lot reste « en attente ».

## Installer une nouvelle version

Quand une nouvelle version de Gestion OM est disponible :

1. **Sauvegardez vos données** : ouvrez **pgAdmin 4** (installé avec PostgreSQL), dépliez *Servers*, puis *PostgreSQL*, puis *Databases* (le mot de passe de l'étape 3 est demandé), faites un clic droit sur `gestion_om` et choisissez **Backup…** (**Sauvegarder…** si pgAdmin est en français) ; donnez un nom de fichier et validez.
2. Téléchargez et extrayez la nouvelle version comme à l'étape 5, **dans un nouveau dossier**.
3. Copiez le fichier `.env` de l'ancien dossier du projet dans le nouveau. Il contient vos réglages et la clé secrète : sans lui, vos sessions et réglages seraient perdus.
4. Ouvrez une fenêtre de commandes dans le **nouveau** dossier (étape 6) et lancez, une par une :

   ```
   composer install
   npm install
   php artisan migrate
   npm run build
   ```

5. Lancez l'application depuis le nouveau dossier (étape 8). Vos données sont là : elles sont dans la base, pas dans le dossier. Une fois que tout fonctionne, vous pouvez supprimer l'ancien dossier.

## En cas de problème

| Message ou situation | Cause | Solution |
|---|---|---|
| `'php' n'est pas reconnu en tant que commande interne` (ou `composer`, `node`, `npm`) | La fenêtre a été ouverte avant l'installation | Fermez la fenêtre de commandes et rouvrez-la ; si cela ne suffit pas, redémarrez l'ordinateur. |
| `l'exécution de scripts est désactivée sur ce système` | La fenêtre est PowerShell, pas l'Invite de commandes | Ouvrez la fenêtre comme indiqué à l'étape 6 (taper `cmd` dans la barre d'adresse). |
| `your php version (8.2…) does not satisfy that requirement` pendant `composer install` | La fenêtre utilise un PHP trop ancien, souvent celui de XAMPP ou WAMP | Suivez l'encadré « Si `php -v` affiche `PHP 8.2` » de l'étape 1, puis relancez `composer install`. |
| `vendor/autoload.php` … `Failed to open stream` | `composer install` n'a pas été lancé ou n'a pas abouti | Relancez `composer install` et lisez les dernières lignes : la cause y est indiquée (souvent un PHP trop ancien, voir la ligne précédente). |
| `could not find driver` | PHP ne sait pas parler à PostgreSQL | Refaites la vérification de l'étape 1 et activez `pdo_pgsql` et `pgsql`. |
| `password authentication failed for user "gestion_om"` | Le compte de base de données n'existe pas ou a un autre mot de passe | Refaites l'étape 4. |
| `database "gestion_om" does not exist` | La base n'a pas été créée | Refaites l'étape 4 (seulement la commande `CREATE DATABASE`). |
| `Connection refused` ou `could not connect to server` | PostgreSQL est arrêté | Redémarrez l'ordinateur. Si cela ne suffit pas : menu Démarrer, tapez `services`, ouvrez **Services**, trouvez la ligne `postgresql-x64-16` et cliquez sur **Démarrer**. |
| `Vite manifest not found` dans la page | L'interface n'a pas été préparée | Lancez `npm run build` dans le dossier du projet. |
| `php artisan serve` affiche `8001` (ou un autre numéro) au lieu de `8000`, et la connexion échoue | L'application tourne déjà dans une autre fenêtre | Fermez cette nouvelle fenêtre et utilisez celle déjà ouverte, à l'adresse http://localhost:8000. |
| La page de connexion revient sans cesse, ou affiche « 419 » | L'adresse utilisée n'est pas la bonne | Tapez exactement **http://localhost:8000** dans le navigateur. |
| « Trop de tentatives. Réessayez dans N minutes. » | Plusieurs erreurs de mot de passe à la suite | Attendez le délai indiqué. |
| `The stream or file … laravel.log could not be opened` | Le dossier du projet est en lecture seule | Déplacez le dossier du projet dans **Documents** et recommencez à l'étape 6. |

Si un autre message apparaît, faites une capture d'écran de la fenêtre de commandes (touches **Windows + Maj + S**) et transmettez-la à la personne qui maintient l'application.

## Installer sur macOS ou Linux

Les étapes sont les mêmes ; seuls les outils d'installation changent.

**macOS**

1. PHP et Composer : installez **Laravel Herd** depuis https://herd.laravel.com (version macOS).
2. Node.js : téléchargez la version **LTS** sur https://nodejs.org.
3. PostgreSQL : installez **Postgres.app** depuis https://postgresapp.com, ouvrez-la et cliquez sur **Initialize**. Pour l'étape 4, double-cliquez sur la base `postgres` dans Postgres.app : une fenêtre Terminal s'ouvre, prête à recevoir les deux commandes `CREATE`.
4. Fenêtre de commandes : ouvrez l'application **Terminal**, tapez `cd` suivi d'une espace, glissez le dossier du projet depuis le Finder dans la fenêtre, puis appuyez sur **Entrée**.
5. À l'étape 7, remplacez `copy .env.example .env` par `cp .env.example .env`.

**Linux (Ubuntu 24.04)**

```
sudo apt install php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-intl composer postgresql
```

Installez Node.js en version LTS depuis https://nodejs.org. Pour l'étape 4, ouvrez la console de la base avec `sudo -u postgres psql`. À l'étape 7, utilisez `cp .env.example .env`.

## Pour les développeurs

Pour lancer les tests automatisés, créez aussi la base de test (dans `psql`, comme à l'étape 4) :

```sql
CREATE DATABASE gestion_om_test OWNER gestion_om ENCODING 'UTF8' TEMPLATE template0;
```

Puis, dans le dossier du projet : `vendor/bin/pest`. Les autres vérifications et le mode développement (`composer run dev`, avec rechargement automatique de l'interface) sont décrits dans le [README](../README.md). Les sauvegardes en ligne de commande (`php artisan om:sauvegarde`) ont besoin de `pg_dump` : sous Windows, indiquez son dossier dans `.env`, par exemple `BACKUP_PG_BIN='C:\Program Files\PostgreSQL\16\bin'`.
