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

`App\Support\Audit::record()` est le point d'entrée unique du journal (`audit_logs`). Sont journalisés : connexions, échecs de connexion, déconnexions, création, modification, désactivation et réactivation des comptes, changements de mot de passe. Les mots de passe ne sont jamais recopiés dans le journal.

## Organisation de l'API

- `POST /auth/login`, `POST /auth/logout` : session Sanctum (cookie + jeton XSRF), 5 tentatives par minute.
- `GET /api/me`, `PUT /api/me/password`
- `GET|POST /api/users`, `PATCH /api/users/{id}`, `POST /api/users/{id}/deactivate`, `POST /api/users/{id}/reactivate`
- `GET /api/audit-logs?action=user.&page=2`
