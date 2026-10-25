<?php

use App\Models\User;

it('limite les essais sur plusieurs comptes depuis une même adresse', function () {
    foreach (range(1, 20) as $attempt) {
        $this->postJson('/auth/login', ['email' => "compte{$attempt}@example.com", 'password' => 'faux'])->assertUnprocessable();
    }

    $this->postJson('/auth/login', ['email' => 'autre@example.com', 'password' => 'faux'])->assertTooManyRequests();
    // Une autre adresse n'est pas concernée.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/auth/login', ['email' => 'autre@example.com', 'password' => 'faux'])
        ->assertUnprocessable();
});

it('limite les essais sur un compte depuis plusieurs adresses, pendant une heure', function () {
    User::factory()->create(['email' => 'a@example.com']);

    foreach (range(1, 30) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$attempt}"])
            ->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'faux'])
            ->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
        ->postJson('/auth/login', ['email' => 'A@example.com', 'password' => 'password'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->travel(61)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
        ->postJson('/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
        ->assertOk();
});

it('envoie les en-têtes de sécurité', function () {
    $response = $this->get('/connexion')->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-inline');

    $this->getJson('/api/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('répond sans erreur serveur à une visite de l\'API sans session', function () {
    // Un navigateur ou un robot n'envoie pas « Accept: application/json ».
    $this->get('/api/me')->assertUnauthorized()->assertJsonStructure(['message']);
    $this->get('/api/exports/mission-orders')->assertUnauthorized();
});

it('impose le https au navigateur une fois la connexion chiffrée', function () {
    $this->get('https://localhost/connexion')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('limite les exports et rapports d\'un contrôleur', function () {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 20) as $attempt) {
        $this->get('/api/exports/anomalies')->assertOk();
    }

    $this->get('/api/exports/anomalies')->assertTooManyRequests();
    $this->getJson('/api/dashboard')->assertOk();
});
