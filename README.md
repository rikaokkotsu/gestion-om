# Gestion OM

Application de contrôle des Ordres de Mission (OM). Les contrôleurs saisissent les OM tels qu'ils figurent sur le document, l'application calcule et signale les anomalies, sans jamais corriger ni bloquer les données contrôlées.

Pile technique : Laravel 13 (PHP 8.3), Vue 3 + TypeScript, Tailwind CSS 4, PostgreSQL 16, tests Pest, analyse statique Larastan.

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
composer run dev              # serveur Laravel + Vite sur http://localhost:8000
```

Pour des données de démonstration : `php artisan migrate:fresh --seed` crée le compte `controleur@example.com` (mot de passe `motdepasse1`) et deux comptes rattachés.

## Vérifications

```sh
vendor/bin/pest                                          # tests (base gestion_om_test)
vendor/bin/phpstan analyse --memory-limit=1G             # analyse statique
vendor/bin/pint --test                                   # style PHP
npm run typecheck                                        # types TypeScript
npm run build                                            # build du front
```

L'intégration continue (`.github/workflows/ci.yml`) lance les mêmes commandes sur chaque pull request.

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

## Organisation de l'API

- `POST /auth/login`, `POST /auth/logout` : session Sanctum (cookie + jeton XSRF), 5 tentatives par minute.
- `GET /api/me`, `PUT /api/me/password`
- `GET|POST /api/users`, `PATCH /api/users/{id}`, `POST /api/users/{id}/deactivate`, `POST /api/users/{id}/reactivate`
- `GET /api/referentials` (toutes les listes), `GET|POST /api/referentials/{type}`, `PATCH /api/referentials/{type}/{id}` avec `type` = `categories`, `places`, `services`, `mission-objects`, `transport-modes`
- `GET /api/employees?search=&service_id=&category_id=&current_place_id=&is_validator=&status=`, `POST /api/employees`, `GET|PATCH /api/employees/{id}`
- `GET /api/imports/{places|employees}/template`, `POST /api/imports/{places|employees}` (`file`, `dry_run`)
- `GET|POST /api/rate-versions`, `GET|PATCH|DELETE /api/rate-versions/{id}` (`PATCH` : `label`, `effective_from`, `rates[]` ; refusé avec 409 sur une version figée)
- `POST /api/per-diem/simulate` (`category_id`, `date` ou `rate_version_id`, `quantities[]` : `consumption_type`, `zone_id`, `quantity`)
- `GET /api/audit-logs?action=user.&page=2`
