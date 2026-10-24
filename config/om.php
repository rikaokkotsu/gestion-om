<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Convention de calcul de la durée d'une mission (§ 8.3)
    |--------------------------------------------------------------------------
    |
    | inclusive_days : jours calendaires, départ et retour compris
    |                  (du 3 au 7 = 5 jours, quelle que soit l'heure) ;
    | nights         : nombre de nuits (du 3 au 7 = 4) ;
    | started_days   : tranches de 24 heures entamées.
    |
    */

    'duration_convention' => env('OM_DURATION_CONVENTION', 'inclusive_days'),

];
