# Gestion OM

Application de contrôle des Ordres de Mission (OM). Les contrôleurs saisissent les OM tels qu'ils figurent sur le document, l'application calcule et signale les anomalies, sans jamais corriger ni bloquer les données contrôlées.

Pile technique : Laravel 13 (PHP 8.3), Vue 3 + TypeScript, Tailwind CSS 4, PostgreSQL 16, tests Pest, analyse statique Larastan.

## Documentation

- [Documentation technique](docs/technique.md) : architecture, modules, modèle de données, sécurité.
- [Installation et exploitation](docs/exploitation.md) : serveur de production, https, mises à jour, sauvegardes et restauration, surveillance.
- [Guide d'utilisation](docs/guide-utilisation.md) : pour les contrôleurs.
- [Rapport de tests et de validation](docs/rapport-de-tests.md) : résultats des tests et recette.

## Démarrer en local

Prérequis : PHP 8.3 avec `pdo_pgsql`, Composer, Node 22, Docker (ou un PostgreSQL 16 local).

```sh
docker compose up -d          # PostgreSQL avec les bases gestion_om et gestion_om_test
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan om:premier-compte # crée le premier compte contrôleur
composer run dev              # serveur Laravel, file d'attente et Vite sur http://localhost:8000
```

Le contrôle par lot passe par la file d'attente (`QUEUE_CONNECTION=database`) : `composer run dev` lance un worker ; en production, faire tourner `php artisan queue:work` en permanence.

Pour des données de démonstration : `php artisan migrate:fresh --seed` crée le compte `controleur@example.com` (mot de passe `motdepasse1`), deux comptes rattachés, quelques lieux et quatre employés.

## Vérifications

```sh
vendor/bin/pest                                          # tests (base gestion_om_test)
vendor/bin/phpstan analyse --memory-limit=1G             # analyse statique
vendor/bin/pint --test                                   # style PHP
npm run typecheck                                        # types TypeScript
npm run build                                            # build du front
```

L'intégration continue (`.github/workflows/ci.yml`) lance les mêmes commandes sur chaque pull request. Le workflow `dependencies.yml` cherche chaque lundi les failles connues des dépendances (`composer audit`, `npm audit`).

## Mise en production

La procédure complète est dans [docs/exploitation.md](docs/exploitation.md). En résumé :

- Partir de [`.env.production.example`](.env.production.example) : `APP_ENV=production` et `APP_DEBUG=false`, adresse en https. En production, les URL sont forcées en https et le cookie de session n'est envoyé qu'en https ; derrière un proxy qui termine le https, renseigner `TRUSTED_PROXIES`.
- Chaque réponse porte les en-têtes de sécurité (`App\Http\Middleware\SecurityHeaders`) : politique de contenu stricte, interdiction de l'affichage dans un cadre, HSTS sur les requêtes https.
- Limites (`AppServiceProvider::configureRateLimiting()`) : connexion à 5 essais par minute pour un compte depuis une adresse, 20 par minute par adresse, 30 par heure par compte ; API à 600 requêtes par minute ; exports et PDF à 20 par minute.
- Faire tourner la file d'attente (`php artisan queue:work`) et le planificateur (`php artisan schedule:run` chaque minute).
- `php artisan om:sauvegarde` (chaque jour à 02:00) sauvegarde la base avec `pg_dump` et un manifeste (empreinte SHA-256, nombre de lignes de chaque table sur le même instantané), puis supprime les sauvegardes de plus de `BACKUP_KEEP_DAYS` jours. `php artisan om:verifier-sauvegarde` (02:30) restaure la dernière sauvegarde dans une base temporaire et compare chaque table ; le rôle PostgreSQL a besoin du droit `CREATEDB`.
- `php artisan om:verifier-installation` vérifie la configuration, la base et les sauvegardes, sans rien modifier.

## Comptes et droits

- Seuls les contrôleurs utilisent l'application ; tous les comptes ont les mêmes droits.
- Un compte ne peut modifier, désactiver ou réactiver que les comptes qu'il a créés. Chacun change son propre mot de passe depuis « Mon compte ».
- Un compte n'est jamais supprimé, il est désactivé : ses sessions sont fermées et les comptes qu'il avait créés passent à son créateur.
- Le premier compte se crée en ligne de commande (`php artisan om:premier-compte`), uniquement quand la base n'en contient aucun.

## Journal d'audit

`App\Support\Audit::record()` est le point d'entrée unique du journal (`audit_logs`). Sont journalisés : connexions, échecs de connexion, déconnexions, création, modification, désactivation et réactivation des comptes, changements de mot de passe, imports, et chaque modification de référentiel, de fiche employé ou de tarif avec sa valeur précédente. Les mots de passe ne sont jamais recopiés dans le journal.

## Référentiels et employés

- Les valeurs du cahier des charges (zones Nord et Autres, catégories DG à 5B, objets de mission, moyens de transport et leur règle de frais) sont créées par les migrations.
- Les référentiels et les fiches employés ne se suppriment pas : ils se désactivent.
- Une fiche employé n'exige que le matricule (unique) et le nom ; une fiche incomplète s'enregistre et sera signalée par le moteur de contrôle.
- Chaque changement d'affectation, y compris le premier, ajoute une ligne à `assignment_histories` (origine : saisie, import ou OM). La date d'une affectation initiale peut rester inconnue.
- Créations et modifications sont journalisées automatiquement par le trait `App\Models\Concerns\Auditable`.

## Import Excel

Page « Import » : lieux d'abord, puis employés. Fichiers `.xlsx` ou `.csv` (virgules ou points-virgules), modèle téléchargeable. Une simulation montre le bilan sans rien enregistrer. Une ligne invalide est rejetée avec son motif sans bloquer les autres ; un matricule existant met la fiche à jour sans effacer les valeurs connues par des cellules vides.

## Barèmes et per diem

- Les barèmes sont versionnés : chaque version s'applique à partir de sa date d'effet jusqu'à la veille de la suivante. La version d'origine (barème du cahier des charges, créé par la migration) n'a pas de date et couvre toute date antérieure. Le calcul retient la version en vigueur à la date de départ.
- Un tarif a trois états : un montant (0 compris), non renseigné (vide) ou sans objet pour la catégorie. Un tarif non renseigné n'est jamais compté à 0 : le calcul est marqué incomplet et dit pourquoi.
- DG, DGA et Directeur ont un forfait repas par jour, réparti par zone comme les nuitées ; petit-déjeuner, déjeuner et dîner sont sans objet pour eux.
- Une nouvelle version copie les tarifs de celle en vigueur à sa date d'effet (ou d'une version choisie). Une version se fige dès qu'un calcul enregistré l'utilise (`RateVersion::lock()`) : ses tarifs et sa date ne changent plus.
- `App\PerDiem\PerDiemCalculator` est le seul endroit où le per diem est calculé. Cas de référence testé : R HC3, 3 jours en zone Nord puis 2 jours ailleurs, 503 000 Ar.

## Saisie des OM

- Un OM se saisit tel qu'il figure sur le document, même incomplet ou incohérent : seules les références (employés, lieux, moyens de transport…) doivent exister. Retour avant départ, quantité négative, validateur missionnaire ou indemnité sur une mission s'enregistrent ; le moteur de contrôle les signale.
- La référence d'OM n'est pas unique en base : un doublon sur le document doit pouvoir être saisi, puis signalé.
- Dates et heures locales (fuseau `Indian/Antananarivo`). La durée selon les dates est calculée à la lecture, à côté de la durée déclarée, selon `OM_DURATION_CONVENTION` : `inclusive_days` (par défaut, du 3 au 7 = 5 jours), `nights` ou `started_days`.
- Chaque missionnaire a sa catégorie retenue sur l'OM (pré-remplie depuis sa fiche), ses quantités par type et par zone, ses totaux déclarés et son per diem déclaré. À chaque enregistrement, `App\Actions\SaveMissionOrder` calcule le per diem et le conserve à côté du déclaré, sans jamais l'écraser.
- Le barème retenu est celui en vigueur à la date de départ ; il est figé dès son premier usage. Une version déjà retenue est gardée tant que la date de départ ne change pas, pour qu'un nouveau barème ne modifie pas un calcul enregistré en silence.
- Pour un OM de type Affectation, la destination devient l'affectation de chaque missionnaire, avec la date de départ comme date d'effet et l'OM comme origine dans l'historique. Rien n'est défait si l'OM change ensuite de type ou est supprimé : l'historique garde la trace.
- Les frais supplémentaires sont un montant global de l'OM, avec une ventilation facultative par nature. Les étapes de transport sont ordonnées dans chaque sens ; sans étape, l'OM n'a aucun moyen de transport.
- Un OM supprimé l'est logiquement (`deleted_at`). Création, modification et suppression sont journalisées avec l'état complet de l'OM avant et après.

## Contrôle et anomalies

- Le contrôle automatique se lance à la demande, sur un OM (bouton « Lancer le contrôle » de sa fiche) ou sur un lot d'OM (période de départ, type, statut ; tous les OM, ou seulement ceux jamais contrôlés ou modifiés depuis). Il ne modifie jamais l'OM.
- Les 30 règles du catalogue sont dans `app/Control/Rules`, une classe par règle, listées dans `App\Control\RuleRegistry` dans l'ordre des catégories A à H du § 13.2. Chaque règle a un code (EMP-01…FRA-02), une nature (erreur, incohérence, donnée manquante, à vérifier) qui donne la priorité par défaut, et se désactive depuis l'onglet « Règles de contrôle ».
- Une anomalie automatique naît « proposée » ; le contrôleur la confirme ou l'écarte (motif obligatoire), puis la transmet et la clôture. Un signalement manuel naît « confirmé ». États : proposée → confirmée ou écartée ; confirmée → transmise, clôturée ou écartée ; transmise → clôturée ; écartée ou clôturée → confirmée (réouverture).
- `App\Control\ControlEngine` évite les doublons : une situation est reconnue d'un contrôle à l'autre par sa règle, l'employé concerné et sa cible (étape, type de repas…). Une anomalie à traiter est conservée et sa description mise à jour ; une anomalie écartée ou clôturée n'est reproposée que si la situation a changé ou avait disparu entre-temps. Une anomalie proposée que le contrôle ne retrouve plus est clôturée ; une anomalie confirmée ou transmise est seulement marquée « plus détectée », le contrôleur décide.
- Le statut de contrôle de l'OM en découle : « contrôle en cours » tant qu'une anomalie est proposée, « avec anomalies » dès qu'une anomalie a été confirmée (même transmise ou clôturée depuis), sinon « sans anomalie » s'il a été contrôlé.
- Chaque anomalie garde son historique (création, changements d'état, commentaires, modifications, nouvelles détections) ; signalements, changements d'état, modifications, contrôles et réglages des règles sont aussi au journal d'audit.

## Recherche, tableau de bord et rapports

- Les critères de recherche des OM (§ 14.1) sont définis une seule fois, dans `App\Search\MissionOrderSearch`, et servent à la liste, au tableau de bord, à l'export Excel et au récapitulatif PDF : un même filtre retient partout les mêmes OM. Critères combinables : référence ou missionnaire, missionnaire (nom ou matricule), catégorie retenue sur l'OM, service du missionnaire, validateur, type, objet, destination, période de départ, période de retour, état de validation, état du contrôle, présence d'anomalies. Missionnaire, catégorie et service visent une même personne de l'OM.
- Présence d'anomalies : « à traiter » (proposée, confirmée ou transmise) ; « à traiter ou traitées » (les mêmes, plus les anomalies clôturées après confirmation) ; « sans anomalie » (aucune des précédentes). Les anomalies écartées et les propositions closes par le contrôle sans confirmation ne comptent pas.
- Le tableau de bord (page d'accueil, `App\Reports\Dashboard`) est calculé à chaque affichage sur les OM enregistrés, hors OM supprimés, pour une période de départ. Le total des per diem calculés ne retient que les calculs complets ; les calculs incomplets (tarif manquant, quantité invalide) sont dénombrés à part, jamais comptés pour 0.
- Exports Excel : le classeur des OM (feuilles OM, Missionnaires, Frais, Transports, Anomalies, Critères) et la liste des anomalies, avec les filtres de l'écran. Montants déclarés et calculés y figurent côte à côte ; un texte qui commence par « = » reste un texte.
- PDF : le rapport de contrôle d'un OM (fiche, per diem déclaré et calculé avec le détail du calcul, frais, transports, anomalies par état avec leur dernier commentaire) et le récapitulatif d'une sélection d'OM (indicateurs puis liste, limitée à 500 OM ; l'export Excel n'a pas de limite). Les PDF sont produits par dompdf, sans ressource distante.
- Chaque export et chaque rapport est inscrit au journal d'audit avec ses critères et le nombre de lignes produites.
- Sauvegardes et vérifications de sauvegarde sont aussi au journal (`backup.created`, `backup.verified`, et leurs échecs), avec l'auteur « Tâche planifiée ».

## Organisation de l'API

- `POST /auth/login`, `POST /auth/logout` : session Sanctum (cookie + jeton XSRF), tentatives limitées (voir « Mise en production »).
- `GET /api/me`, `PUT /api/me/password`
- `GET|POST /api/users`, `PATCH /api/users/{id}`, `POST /api/users/{id}/deactivate`, `POST /api/users/{id}/reactivate`
- `GET /api/referentials` (toutes les listes), `GET|POST /api/referentials/{type}`, `PATCH /api/referentials/{type}/{id}` avec `type` = `categories`, `places`, `services`, `mission-objects`, `transport-modes`
- `GET /api/employees?search=&service_id=&category_id=&current_place_id=&is_validator=&status=`, `POST /api/employees`, `GET|PATCH /api/employees/{id}`
- `GET /api/imports/{places|employees}/template`, `POST /api/imports/{places|employees}` (`file`, `dry_run`)
- `GET|POST /api/rate-versions`, `GET|PATCH|DELETE /api/rate-versions/{id}` (`PATCH` : `label`, `effective_from`, `rates[]` ; refusé avec 409 sur une version figée)
- `POST /api/per-diem/simulate` (`category_id`, `date` ou `rate_version_id`, `quantities[]` : `consumption_type`, `zone_id`, `quantity`)
- `GET /api/mission-orders?search=&missionary=&category_id=&service_id=&validator=&type=&mission_object_id=&destination_place_id=&departure_from=&departure_to=&return_from=&return_to=&administrative_status=&control_status=&anomalies=open|any|none&employee_id=`, `POST /api/mission-orders`, `GET|PATCH|DELETE /api/mission-orders/{id}` ; `GET /api/employees/{id}/mission-orders` (OM dont l'employé est missionnaire ou validateur)
- `POST /api/mission-orders/{id}/control` (contrôle immédiat), `GET /api/mission-orders/{id}/anomalies`
- `GET /api/anomalies?state=open|all|PROPOSED…&category=&priority=&source=&rule_code=&from=&to=&mission_order_id=&employee_id=&control_run_id=&search=`, `POST /api/anomalies` (signalement manuel), `GET|PATCH /api/anomalies/{id}`, `POST /api/anomalies/{id}/transition` (`state`, `comment`), `POST /api/anomalies/{id}/comments`
- `GET /api/control-rules`, `PATCH /api/control-rules/{code}` (`is_enabled`)
- `GET /api/dashboard` (mêmes critères que la liste des OM), `GET /api/reports/summary` (récapitulatif PDF, mêmes critères), `GET /api/reports/mission-orders/{id}` (rapport de contrôle PDF)
- `GET /api/exports/mission-orders` (Excel, mêmes critères que la liste des OM), `GET /api/exports/anomalies` (Excel, mêmes critères que la liste des anomalies)
- `GET|POST /api/control-runs` (contrôle par lot : `scope` = `all` ou `pending`, `departure_from`, `departure_to`, `type`, `administrative_status`), `GET /api/control-runs/{id}`
- `GET /api/audit-logs?action=user.&page=2`
