<?php

/*
|--------------------------------------------------------------------------
| Sauvegardes de la base PostgreSQL
|--------------------------------------------------------------------------
|
| Une sauvegarde par jour (om:sauvegarde), vérifiée en la restaurant dans une
| base temporaire (om:verifier-sauvegarde). Voir docs/exploitation.md.
|
*/

return [

    // Dossier des sauvegardes ; à recopier chaque jour hors du serveur.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Durée de conservation, en jours ; la sauvegarde la plus récente est toujours gardée.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    // Dossier de pg_dump, pg_restore et psql s'ils ne sont pas dans le PATH (ex. /usr/lib/postgresql/16/bin).
    'pg_bin' => env('BACKUP_PG_BIN', ''),

    // Base temporaire de la vérification ; le compte de l'application doit pouvoir la créer (droit CREATEDB).
    'verify_database' => env('BACKUP_VERIFY_DATABASE'),

    // Heures des tâches planifiées (heure locale de l'application).
    'schedule' => [
        'backup' => env('BACKUP_TIME', '02:00'),
        'verify' => env('BACKUP_VERIFY_TIME', '02:30'),
    ],

];
