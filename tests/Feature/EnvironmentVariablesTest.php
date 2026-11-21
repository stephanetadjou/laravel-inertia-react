<?php

// Variables d'environnement CLIENTLYX_* : le nouveau nom est lu d'abord,
// l'ancien MENENTIA_* reste un repli pour les serveurs déjà configurés.

function setTestEnv(string $key, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        return;
    }

    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

function platformToken(): mixed
{
    return (require base_path('config/services.php'))['meta_whatsapp']['platform']['token'];
}

beforeEach(function () {
    $this->savedEnv = [
        'CLIENTLYX_WHATSAPP_TOKEN' => $_ENV['CLIENTLYX_WHATSAPP_TOKEN'] ?? null,
        'MENENTIA_WHATSAPP_TOKEN' => $_ENV['MENENTIA_WHATSAPP_TOKEN'] ?? null,
    ];
});

afterEach(function () {
    foreach ($this->savedEnv as $key => $value) {
        setTestEnv($key, $value);
    }
});

test('le token de la plateforme lit CLIENTLYX_WHATSAPP_TOKEN en priorité', function () {
    setTestEnv('CLIENTLYX_WHATSAPP_TOKEN', 'nouveau');
    setTestEnv('MENENTIA_WHATSAPP_TOKEN', 'ancien');

    expect(platformToken())->toBe('nouveau');
});

test('le token de la plateforme retombe sur MENENTIA_WHATSAPP_TOKEN', function () {
    setTestEnv('CLIENTLYX_WHATSAPP_TOKEN', null);
    setTestEnv('MENENTIA_WHATSAPP_TOKEN', 'ancien');

    expect(platformToken())->toBe('ancien');

    setTestEnv('CLIENTLYX_WHATSAPP_TOKEN', '');

    expect(platformToken())->toBe('ancien');
});
