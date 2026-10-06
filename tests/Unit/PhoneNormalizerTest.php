<?php

use App\Services\PhoneNormalizer;

it('normalizes common Gabonese mobile number variants to 241 + 8 digits', function (string $raw, string $expected) {
    expect(PhoneNormalizer::normalize($raw))->toBe($expected);
})->with([
    'local 8 chiffres' => ['62605054', '24162605054'],
    'local avec espaces' => ['62 60 50 54', '24162605054'],
    'local avec points/tirets' => ['62.60.50-54', '24162605054'],
    '0 initial' => ['062605054', '24162605054'],
    '0 initial avec espaces' => ['06 26 05 05 4', '24162605054'],
    'déjà normalisé' => ['24162605054', '24162605054'],
    '+241' => ['+241 62 60 50 54', '24162605054'],
    '+241 collé' => ['+24177123456', '24177123456'],
    '00241' => ['00241 77 12 34 56', '24177123456'],
    '+241 suivi du 0 local' => ['+241 062605054', '24162605054'],
    '241 suivi du 0 local' => ['241077123456', '24177123456'],
    'cellule Excel numérique' => ['74129782.0', '24174129782'],
    'espaces autour' => ['  74129782 ', '24174129782'],
]);

it('rejects numbers that are not a Gabonese mobile number', function (string $raw) {
    expect(PhoneNormalizer::normalize($raw))->toBeNull();
})->with([
    '9 chiffres sans 0 initial' => ['612345678'],
    '9 chiffres commençant par 7' => ['712345678'],
    'trop court' => ['123'],
    '7 chiffres' => ['6260505'],
    'premier chiffre ni 6 ni 7' => ['12345678'],
    'fixe (0 puis 1)' => ['011234567'],
    '+241 avec 9 chiffres' => ['+241 612345678'],
    '241 + 7 chiffres' => ['2416260505'],
    'autre indicatif' => ['+33612345678'],
    'vide' => [''],
    'texte' => ['pas de numéro'],
]);
