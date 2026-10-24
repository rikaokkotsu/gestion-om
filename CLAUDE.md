# Gestion OM

Application de contrôle des Ordres de Mission. Voir README.md pour l'installation et les commandes.

## Principes métier à respecter

- L'application signale, elle ne corrige ni ne bloque jamais : une anomalie métier doit pouvoir être enregistrée puis signalée. Seules les contraintes d'intégrité (relations, identifiants) vont en base.
- Chaque montant existe en version déclarée (lue sur le document) et calculée ; le calcul n'écrase jamais le déclaré.
- Les règles de calcul et de détection sont centralisées côté serveur.

## Conventions

- Interface, messages et tests en français ; code (classes, colonnes) en anglais sauf termes métier.
- Toute opération sensible passe par `App\Support\Audit::record()`.
- Tests Pest sur PostgreSQL (`gestion_om_test`), pas sur SQLite.
- Avant de pousser : `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`.
