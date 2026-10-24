<?php

use App\Control\RuleRegistry;
use App\Models\Category;
use App\Models\Employee;
use App\Models\MissionObject;
use App\Models\Rate;
use Tests\Concerns\BuildsMissionOrders;

uses(BuildsMissionOrders::class);

beforeEach(function () {
    $this->setUpMissionOrders();
});

it('ne relève rien sur l\'OM de référence', function () {
    $order = $this->saveOrder();

    $findings = [];
    foreach (array_keys(app(RuleRegistry::class)->all()) as $code) {
        foreach ($this->findings($order, $code) as $description) {
            $findings[] = "{$code} : {$description}";
        }
    }

    expect($findings)->toBe([]);
});

it('compte trente règles, chacune avec un code unique', function () {
    $codes = array_keys(app(RuleRegistry::class)->all());

    expect($codes)->toHaveCount(30)
        ->and(array_unique($codes))->toHaveCount(30);
});

// A. Employés

it('EMP-01 : relève la fiche désactivée d\'un missionnaire ou du validateur', function () {
    $order = $this->saveOrder();
    $this->rakoto->update(['is_active' => false]);
    $this->rabe->update(['is_active' => false]);

    expect($this->findings($order, 'EMP-01'))->toBe([
        'La fiche de Rakoto Jean (M001) est désactivée.',
        'La fiche du validateur Rabe Hery (M002) est désactivée.',
    ]);
});

it('EMP-02 : relève une catégorie manquante sur la fiche ou sur l\'OM, ou désactivée', function () {
    $andry = $this->employee('M003', 'Andry', 'Paul', 'R_HC3', ['category_id' => null]);
    $order = $this->saveOrder(['employees' => [
        $this->member($this->rakoto),
        $this->member($andry, ['category_id' => null]),
    ]]);
    Category::where('code', 'R_HC3')->firstOrFail()->update(['is_active' => false]);

    expect($this->findings($order, 'EMP-02'))->toBe([
        'La catégorie retenue pour Rakoto Jean (M001) sur l\'OM (R HC3) est désactivée dans le référentiel.',
        'La fiche d\'Andry Paul (M003) n\'a pas de catégorie.',
        'Aucune catégorie n\'est retenue pour Andry Paul (M003) sur l\'OM.',
    ]);
});

it('EMP-03 : relève les informations administratives manquantes de la fiche', function () {
    $order = $this->saveOrder();
    $this->rakoto->update(['position' => null, 'account_number' => '']);

    expect($this->findings($order, 'EMP-03'))->toBe([
        'Fiche de Rakoto Jean (M001) incomplète : poste et numéro de compte manquants.',
    ]);
});

// B. Validations

it('VAL-01 : relève un validateur parmi les missionnaires', function () {
    $order = $this->saveOrder(['employees' => [$this->member($this->rakoto), $this->member($this->rabe, ['quantities' => [], 'totals' => []])]]);

    expect($this->findings($order, 'VAL-01'))->toBe([
        'Le validateur Rabe Hery (M002) figure parmi les missionnaires de l\'OM.',
    ]);
});

it('VAL-02 : relève un validateur non habilité', function () {
    $rasoa = $this->employee('M004', 'Rasoa', 'Fara', '5A');
    $order = $this->saveOrder(['validator_id' => $rasoa->id]);

    expect($this->findings($order, 'VAL-02'))->toBe([
        'Rasoa Fara (M004) n\'est pas habilité à valider les OM.',
    ]);
});

it('VAL-03 : relève un OM validé sans validateur ni date de validation', function () {
    $order = $this->saveOrder(['validator_id' => null, 'validated_at' => null]);
    $pending = $this->saveOrder(['reference' => 'OM-2026-002', 'administrative_status' => 'PENDING', 'validator_id' => null, 'validated_at' => null]);

    expect($this->findings($order, 'VAL-03'))->toBe([
        'L\'OM est validé mais aucun validateur n\'est indiqué.',
        'L\'OM est validé mais la date de validation n\'est pas indiquée.',
    ])->and($this->findings($pending, 'VAL-03'))->toBe([]);
});

// C. OM

it('OM-01 : relève une référence déjà utilisée, sans tenir compte de la casse ni des OM supprimés', function () {
    $order = $this->saveOrder();
    $twin = $this->saveOrder(['reference' => ' om-2026-001 ']);
    $this->saveOrder()->delete();

    expect($this->findings($order, 'OM-01'))->toBe(["La référence OM-2026-001 est aussi celle de 1 autre OM (n° {$twin->id})."]);
});

it('OM-02 : relève une référence, un type ou un objet manquant', function () {
    $order = $this->saveOrder(['reference' => null, 'type' => null, 'mission_object_id' => null]);

    expect($this->findings($order, 'OM-02'))->toBe([
        'La référence de l\'OM n\'est pas renseignée.',
        'Le type d\'OM (mission ou affectation) n\'est pas renseigné.',
        'L\'objet de la mission n\'est pas renseigné.',
    ]);
});

it('OM-03 : relève un OM sans missionnaire', function () {
    $order = $this->saveOrder(['employees' => []]);

    expect($this->findings($order, 'OM-03'))->toBe(['L\'OM ne désigne aucun missionnaire.']);
});

it('OM-04 : relève un lieu manquant ou sans zone', function () {
    $order = $this->saveOrder(['departure_place_id' => null, 'destination_place_id' => $this->place('Ambanja')]);
    $assignment = $this->saveOrder(['reference' => 'OM-2026-002', 'type' => 'AFFECTATION', 'destination_place_id' => null]);

    expect($this->findings($order, 'OM-04'))->toBe([
        'Le lieu de départ n\'est pas renseigné.',
        'Le lieu de destination (Ambanja) n\'a pas de zone dans le référentiel.',
    ])->and($this->findings($assignment, 'OM-04'))->toBe([]);
});

it('OM-05 : relève un type d\'OM incohérent avec l\'objet de mission', function () {
    $assignment = $this->saveOrder(['type' => 'AFFECTATION']);
    $mission = $this->saveOrder(['reference' => 'OM-2026-002', 'mission_object_id' => MissionObject::where('label', 'Affectation')->value('id')]);

    expect($this->findings($assignment, 'OM-05'))->toBe(['L\'OM est de type Affectation mais son objet est « VPC et relance ».'])
        ->and($this->findings($mission, 'OM-05'))->toBe(['L\'OM est de type Mission mais son objet est « Affectation ».']);
});

// D. Chronologie

it('DAT-01 : relève un retour antérieur au départ', function () {
    $order = $this->saveOrder(['departure_at' => '2026-11-07 08:00', 'return_at' => '2026-11-03 17:30']);

    expect($this->findings($order, 'DAT-01'))->toBe(['Retour le 03/11/2026 à 17:30, avant le départ du 07/11/2026 à 08:00.'])
        ->and($this->findings($order, 'DAT-02'))->toBe([]);
});

it('DAT-02 : relève une durée déclarée différente de la durée selon les dates', function () {
    $order = $this->saveOrder(['declared_duration' => 4]);

    expect($this->findings($order, 'DAT-02'))->toBe([
        'Durée déclarée : 4 jours ; durée selon les dates : 5 jours (jours calendaires, départ et retour compris).',
    ]);
});

it('DAT-03 : relève des dates ou une durée manquantes, ou une durée nulle ou négative', function () {
    $missing = $this->saveOrder(['departure_at' => null, 'return_at' => null, 'declared_duration' => null]);
    $negative = $this->saveOrder(['reference' => 'OM-2026-002', 'declared_duration' => -2]);

    expect($this->findings($missing, 'DAT-03'))->toBe([
        'La date de départ n\'est pas renseignée.',
        'La date de retour n\'est pas renseignée.',
        'La durée de la mission n\'est pas renseignée.',
    ])->and($this->findings($negative, 'DAT-03'))->toBe(['La durée déclarée (-2) est nulle ou négative.']);
});

it('DAT-04 : signale des quantités supérieures à la durée, pour vérification', function () {
    $order = $this->saveOrder(['return_at' => '2026-11-06 17:30', 'declared_duration' => 4]);

    expect($this->findings($order, 'DAT-04'))->toBe([
        'Rakoto Jean (M001) : 5 × Petit-déjeuner pour une mission de 4 jours.',
    ]);
});

// E. Transport

it('TRA-01 : relève des frais sur un moyen qui n\'en engendre pas, ou des frais de déplacement sans moyen payant', function () {
    $order = $this->saveOrder(['legs' => [
        ['direction' => 'OUTBOUND', 'transport_mode_id' => $this->mode('Voiture de service'), 'from_place_id' => $this->place('Antananarivo'), 'to_place_id' => $this->place('Antsiranana'), 'cost' => 80000],
    ]]);
    $withoutLegs = $this->saveOrder(['reference' => 'OM-2026-002', 'legs' => []]);

    expect($this->findings($order, 'TRA-01'))->toBe([
        'Étape aller n° 1 (Voiture de service) : 80 000 Ar de frais, alors que ce moyen n\'engendre pas de frais de transport.',
        'Des frais de déplacement sont ventilés (1 200 000 Ar) alors qu\'aucune étape n\'utilise un moyen payant.',
    ])->and($this->findings($withoutLegs, 'TRA-01'))->toBe([
        'Des frais de déplacement sont ventilés (1 200 000 Ar) alors qu\'aucune étape de transport n\'est renseignée.',
    ]);
});

it('TRA-02 : relève une étape de transport incomplète', function () {
    $data = $this->cleanOrderData();
    $data['legs'][1] = ['direction' => 'RETURN', 'transport_mode_id' => null, 'from_place_id' => $this->place('Antsiranana'), 'to_place_id' => null, 'cost' => 600000];
    $order = $this->saveOrder($data);

    expect($this->findings($order, 'TRA-02'))->toBe([
        'Étape retour n° 1 : moyen de transport et lieu d\'arrivée non renseignés.',
    ]);
});

it('TRA-03 : relève des étapes qui ne s\'enchaînent pas ou ne relient pas les lieux de l\'OM', function () {
    $avion = $this->mode('Avion');
    $order = $this->saveOrder(['legs' => [
        ['direction' => 'OUTBOUND', 'transport_mode_id' => $avion, 'from_place_id' => $this->place('Antananarivo'), 'to_place_id' => $this->place('Toamasina'), 'cost' => 300000],
        ['direction' => 'OUTBOUND', 'transport_mode_id' => $avion, 'from_place_id' => $this->place('Mahajanga'), 'to_place_id' => $this->place('Antsiranana'), 'cost' => 300000],
        ['direction' => 'RETURN', 'transport_mode_id' => $avion, 'from_place_id' => $this->place('Toamasina'), 'to_place_id' => $this->place('Toamasina'), 'cost' => 600000],
    ]]);

    expect($this->findings($order, 'TRA-03'))->toBe([
        'Trajet aller : arrivée de l\'étape n° 1 (Toamasina) différente du départ de l\'étape n° 2 (Mahajanga).',
        'Trajet retour : départ de la première étape (Toamasina) différent du lieu de destination de l\'OM (Antsiranana).',
        'Étape retour n° 1 : départ et arrivée au même lieu (Toamasina).',
        'Trajet retour : arrivée de la dernière étape (Toamasina) différente du lieu de départ de l\'OM (Antananarivo).',
    ]);
});

it('TRA-04 : signale un moyen payant sans frais, ou des frais d\'étapes différents de la ventilation', function () {
    $data = $this->cleanOrderData(['costs' => [['nature' => 'HEALTH', 'label' => null, 'amount' => 50000]], 'additional_costs' => 50000]);
    $data['legs'][0]['cost'] = null;
    $data['legs'][1]['cost'] = null;
    $withoutCosts = $this->saveOrder($data);
    $mismatch = $this->saveOrder(['reference' => 'OM-2026-002', 'costs' => [
        ['nature' => 'TRANSPORT', 'label' => null, 'amount' => 1000000],
        ['nature' => 'HEALTH', 'label' => null, 'amount' => 250000],
    ]]);

    expect($this->findings($withoutCosts, 'TRA-04'))->toBe([
        'Moyen payant utilisé (Avion) sans aucun frais de transport renseigné, ni sur les étapes ni dans la ventilation des frais.',
    ])->and($this->findings($mismatch, 'TRA-04'))->toBe([
        'Frais portés sur les étapes : 1 200 000 Ar ; frais de déplacement ventilés : 1 000 000 Ar.',
    ]);
});

// F. Affectations

it('AFF-01 : relève une indemnité d\'affectation sur un OM de mission', function () {
    $order = $this->saveOrder(['assignment_allowance' => 300000]);

    expect($this->findings($order, 'AFF-01'))->toBe(['Indemnité d\'affectation de 300 000 Ar sur un OM de type Mission.']);
});

it('AFF-02 : relève un OM d\'affectation sans destination', function () {
    $order = $this->saveOrder(['type' => 'AFFECTATION', 'destination_place_id' => null]);

    expect($this->findings($order, 'AFF-02'))->toBe(['OM d\'affectation sans lieu de destination : la nouvelle affectation ne peut pas être déterminée.']);
});

it('AFF-03 : rapproche les changements d\'affectation rattachés à l\'OM de l\'OM lui-même', function () {
    $assignment = ['type' => 'AFFECTATION', 'mission_object_id' => MissionObject::where('label', 'Affectation')->value('id')];
    $andry = $this->employee('M003', 'Andry', 'Paul', 'R_HC3');

    // Affectation appliquée : rien à relever.
    $order = $this->saveOrder([...$assignment, 'employees' => [$this->member($this->rakoto), $this->member($andry)]]);
    expect($this->findings($order, 'AFF-03'))->toBe([]);

    // Andry n'est plus missionnaire, et l'OM change de destination alors que Rakoto y est déjà.
    Employee::whereKey($this->rakoto->id)->update(['current_place_id' => $this->place('Toamasina')]);
    $order = $this->saveOrder(['destination_place_id' => $this->place('Toamasina'), 'employees' => [$this->member($this->rakoto)]], $order);
    expect($this->findings($order, 'AFF-03'))->toBe([
        'Le changement d\'affectation rattaché à cet OM place Rakoto Jean (M001) à Antsiranana, alors que la destination de l\'OM est Toamasina.',
        'Un changement d\'affectation d\'Andry Paul (M003) est rattaché à cet OM, alors que cette personne n\'en est plus missionnaire.',
    ]);

    // L'OM n'est plus de type Affectation.
    $order = $this->saveOrder(['type' => 'MISSION'], $order);
    expect($this->findings($order, 'AFF-03'))->toBe([
        'Un changement d\'affectation de Rakoto Jean (M001) est rattaché à cet OM, qui n\'est plus de type Affectation.',
        'Un changement d\'affectation d\'Andry Paul (M003) est rattaché à cet OM, qui n\'est plus de type Affectation.',
    ]);

    // Un missionnaire déjà affecté à la destination : aucun changement rattaché.
    $already = $this->saveOrder([...$assignment, 'reference' => 'OM-2026-002', 'destination_place_id' => $this->place('Antananarivo'), 'employees' => [$this->member($this->rabe, ['quantities' => [], 'totals' => []])], 'validator_id' => null]);
    expect($this->findings($already, 'AFF-03'))->toBe([
        'Aucun changement d\'affectation n\'est rattaché à cet OM pour Rabe Hery (M002), alors que l\'OM l\'affecte à Antananarivo.',
    ]);
});

it('AFF-04 : relève une affectation actuelle différente de la dernière ligne de l\'historique', function () {
    $order = $this->saveOrder();
    Employee::whereKey($this->rakoto->id)->update(['current_place_id' => $this->place('Toamasina')]);

    expect($this->findings($order, 'AFF-04'))->toBe([
        'L\'affectation actuelle de Rakoto Jean (M001) (Toamasina) diffère de la dernière ligne de son historique (Antananarivo).',
    ]);
});

// G. Per diem

it('PD-01 : relève un per diem déclaré différent du calcul, sans modifier le déclaré', function () {
    $order = $this->saveOrder(['employees' => [$this->member($this->rakoto, ['declared_per_diem' => 500000])]]);

    expect($this->findings($order, 'PD-01'))->toBe([
        'Rakoto Jean (M001) : per diem déclaré 500 000 Ar, calculé 503 000 Ar (déclaré inférieur de 3 000 Ar).',
    ])->and($order->employees()->value('declared_per_diem'))->toBe(500000);
});

it('PD-02 : relève un tarif absent, une absence de barème ou un per diem déclaré manquant', function () {
    Rate::query()
        ->where('category_id', $this->rakoto->category_id)
        ->where('consumption_type', 'NUITEE')
        ->where('zone_id', $this->nord)
        ->update(['amount' => null]);
    $order = $this->saveOrder(['employees' => [$this->member($this->rakoto, ['declared_per_diem' => null])]]);

    expect($this->findings($order, 'PD-02'))->toBe([
        'Rakoto Jean (M001) : tarif absent du barème « Barème du cahier des charges v1.0 » pour la catégorie R HC3 : Nuitée (Zone Nord).',
        'Rakoto Jean (M001) : le per diem déclaré n\'est pas renseigné.',
    ]);

    $member = $order->employees()->firstOrFail();
    $member->forceFill(['calculation' => [...$member->calculation, 'rate_version' => null]])->save();
    expect($this->findings($order, 'PD-02')[0])->toBe('Rakoto Jean (M001) : aucun barème ne s\'applique au 03/11/2026, le per diem ne peut pas être calculé.');
});

it('PD-03 : relève une consommation sans objet pour la catégorie', function () {
    $dg = $this->employee('M005', 'Rakotobe', 'Lalao', 'DG');
    $order = $this->saveOrder(['employees' => [$this->member($dg, ['declared_per_diem' => null, 'totals' => [], 'quantities' => [
        ['consumption_type' => 'PETIT_DEJ', 'zone_id' => $this->nord, 'quantity' => 2],
        ['consumption_type' => 'NUITEE', 'zone_id' => $this->nord, 'quantity' => 2],
    ]])]]);

    expect($this->findings($order, 'PD-03'))->toBe([
        'Rakotobe Lalao (M005) : Petit-déjeuner (Zone Nord) sans objet pour la catégorie Directeur Général.',
    ]);
});

it('PD-04 : relève une catégorie retenue sur l\'OM différente de celle de la fiche', function () {
    $order = $this->saveOrder(['employees' => [$this->member($this->rakoto, ['category_id' => Category::where('code', '5A')->value('id'), 'declared_per_diem' => null])]]);

    expect($this->findings($order, 'PD-04'))->toBe([
        'Rakoto Jean (M001) : catégorie 5A sur l\'OM, R HC3 sur la fiche employé.',
    ]);
});

it('PD-05 : relève une quantité négative ou un total différent de la somme des zones', function () {
    $member = $this->member($this->rakoto);
    $member['quantities'][0]['quantity'] = -1;
    $member['totals'][3]['total'] = 5;
    $order = $this->saveOrder(['employees' => [$member]]);

    expect($this->findings($order, 'PD-05'))->toBe([
        'Rakoto Jean (M001) : quantité négative pour Petit-déjeuner (Zone Nord) : -1.',
        'Rakoto Jean (M001) : Petit-déjeuner, total déclaré 5, somme des zones 1 ; Nuitée, total déclaré 5, somme des zones 4.',
    ]);
});

// H. Frais

it('FRA-01 : relève un montant global manquant ou une ligne de ventilation sans montant', function () {
    $order = $this->saveOrder(['additional_costs' => null, 'costs' => [
        ['nature' => 'TRANSPORT', 'label' => null, 'amount' => 1200000],
        ['nature' => 'CONTINGENCY', 'label' => 'Réparation', 'amount' => null],
    ]]);

    expect($this->findings($order, 'FRA-01'))->toBe([
        'Le montant global des frais supplémentaires n\'est pas renseigné, alors que des frais sont ventilés.',
        'Ligne de ventilation sans montant : Imprévus (Réparation).',
    ]);
});

it('FRA-02 : relève une ventilation ou des frais d\'étapes incompatibles avec le montant global', function () {
    $order = $this->saveOrder(['additional_costs' => 1000000]);

    expect($this->findings($order, 'FRA-02'))->toBe([
        'Somme de la ventilation : 1 250 000 Ar ; montant global des frais supplémentaires : 1 000 000 Ar.',
        'Les frais portés sur les étapes (1 200 000 Ar) dépassent le montant global des frais supplémentaires (1 000 000 Ar).',
    ]);
});
