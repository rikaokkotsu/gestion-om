<?php

use App\Support\MissionDuration;
use Illuminate\Support\Carbon;

it('compte la durée selon la convention retenue', function (string $convention, int $expected) {
    $departure = Carbon::parse('2026-11-03 08:00');
    $return = Carbon::parse('2026-11-07 17:30');

    expect(MissionDuration::between($departure, $return, $convention))->toBe($expected);
})->with([
    'jours calendaires inclusifs' => [MissionDuration::INCLUSIVE_DAYS, 5],
    'nuits' => [MissionDuration::NIGHTS, 4],
    'tranches de 24 h entamées' => [MissionDuration::STARTED_DAYS, 5],
]);

it('compte une journée pour un aller-retour dans la journée', function () {
    expect(MissionDuration::between(Carbon::parse('2026-11-03 06:00'), Carbon::parse('2026-11-03 18:00'), MissionDuration::INCLUSIVE_DAYS))->toBe(1)
        ->and(MissionDuration::between(Carbon::parse('2026-11-03 06:00'), Carbon::parse('2026-11-03 18:00'), MissionDuration::NIGHTS))->toBe(0);
});

it('donne une durée négative quand le retour précède le départ', function () {
    expect(MissionDuration::between(Carbon::parse('2026-11-07 08:00'), Carbon::parse('2026-11-03 08:00'), MissionDuration::INCLUSIVE_DAYS))->toBe(-3);
});
