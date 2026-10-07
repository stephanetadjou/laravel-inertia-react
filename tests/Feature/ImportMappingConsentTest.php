<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

function importSetup(): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    return [$store, $user];
}

function csvFile(string $content, string $name = 'clients.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('reads a semicolon separated file exported by Excel in French', function () {
    [$store, $user] = importSetup();
    $csv = "\xEF\xBB\xBFnom;telephone;consentement\nAwa Mba;062 12 34 56;oui\nJean Ndong;077123456;non\n";

    $this->actingAs($user)->post('/imports', ['store_id' => $store->id, 'file' => csvFile($csv)])->assertSessionHas('success');

    expect(Contact::where('store_id', $store->id)->pluck('consent_status', 'name')->all())
        ->toBe(['Awa Mba' => 'opt_in', 'Jean Ndong' => 'opt_out']);
});

it('converts Windows-1252 accents from old Excel exports', function () {
    [$store, $user] = importSetup();
    $csv = mb_convert_encoding("nom;telephone\nHélène Obiang;062123456\n", 'Windows-1252', 'UTF-8');

    $this->actingAs($user)->post('/imports', ['store_id' => $store->id, 'file' => csvFile($csv)]);

    expect(Contact::sole()->name)->toBe('Hélène Obiang');
});

it('detects the most frequent separator', function (string $line, string $expected) {
    expect(SpreadsheetReader::detectDelimiter($line))->toBe($expected);
})->with([
    ['nom;telephone;consentement', ';'],
    ['nom,telephone', ','],
    ["nom\ttelephone", "\t"],
    ['nom', ','],
]);

it('lets the user force the separator', function () {
    [$store, $user] = importSetup();
    // Virgule dans le nom : avec « ; » imposé, la ligne reste correcte.
    $csv = "nom;telephone\nMba, Awa;062123456\n";

    $this->actingAs($user)->post('/imports', ['store_id' => $store->id, 'file' => csvFile($csv), 'delimiter' => 'semicolon']);

    expect(Contact::sole()->name)->toBe('Mba, Awa');
});

it('recognises common header synonyms and shows the detected mapping in the preview', function () {
    [$store, $user] = importSetup();
    $csv = "Client;Téléphone;Opt-in\nAwa Mba;062123456;oui\n";

    $this->actingAs($user)->post('/imports/preview', ['store_id' => $store->id, 'file' => csvFile($csv)])
        ->assertInertia(fn ($page) => $page
            ->where('preview.headers', ['Client', 'Téléphone', 'Opt-in'])
            ->where('preview.mapping.nom', 0)
            ->where('preview.mapping.telephone', 1)
            ->where('preview.mapping.consentement', 2)
            ->where('preview.total_valid', 1)
            ->has('fields'));
});

it('imports with columns chosen by the user', function () {
    [$store, $user] = importSetup();
    $csv = "Identité;Portable perso;Accord;Quand;Comment\nAwa Mba;062123456;oui;15/09/2026;Formulaire caisse\n";

    $this->actingAs($user)->post('/imports', [
        'store_id' => $store->id,
        'file' => csvFile($csv),
        'mapping' => ['nom' => 0, 'telephone' => 1, 'consentement' => 2, 'date_consentement' => 3, 'source_consentement' => 4],
    ])->assertSessionHas('success');

    $contact = Contact::sole();
    expect($contact->name)->toBe('Awa Mba')
        ->and($contact->consent_status)->toBe('opt_in')
        ->and($contact->consent_at->toDateString())->toBe('2026-09-15')
        ->and($contact->consent_source)->toBe('Formulaire caisse');
});

it('reports missing name or phone columns instead of importing nothing silently', function () {
    [$store, $user] = importSetup();

    $this->actingAs($user)->post('/imports/preview', ['store_id' => $store->id, 'file' => csvFile("a;b\nx;y\n")])
        ->assertInertia(fn ($page) => $page
            ->where('preview.total_valid', 0)
            ->where('preview.skipped.0', fn (string $m) => str_contains($m, 'introuvables')));
});

it('dates an imported consent and sources it as import by default', function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    [$store, $user] = importSetup();

    $this->actingAs($user)->post('/imports', ['store_id' => $store->id, 'file' => csvFile("nom;telephone;consentement\nAwa;062123456;oui\n")]);

    $contact = Contact::sole();
    expect($contact->consent_source)->toBe('import')
        ->and($contact->consent_at->toDateTimeString())->toBe('2026-10-07 10:00:00');
});

it('keeps the original consent date when the file repeats the same answer', function () {
    [$store, $user] = importSetup();
    $contact = Contact::factory()->create([
        'store_id' => $store->id, 'phone_e164' => '24162123456', 'consent_status' => 'opt_in',
        'consent_at' => '2026-01-10 09:00:00', 'consent_source' => 'QR caisse',
    ]);

    $this->actingAs($user)->post('/imports', ['store_id' => $store->id, 'file' => csvFile("nom;telephone;consentement\nAwa;062123456;oui\n")]);

    $contact->refresh();
    expect($contact->consent_at->toDateString())->toBe('2026-01-10')
        ->and($contact->consent_source)->toBe('QR caisse');
});

it('dates and sources every consent change made in the application', function () {
    Carbon::setTestNow('2026-10-07 11:00:00');
    $contact = Contact::factory()->create(['consent_status' => 'unknown']);

    $contact->update(['consent_status' => 'opt_in']);

    expect($contact->fresh()->consent_source)->toBe('manuel')
        ->and($contact->fresh()->consent_at->toDateTimeString())->toBe('2026-10-07 11:00:00');
});

it('records a Stop answer as the source of the opt-out', function () {
    $store = Store::factory()->create(['whatsapp_phone_number_id' => '111']);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288', 'consent_status' => 'opt_in', 'consent_source' => 'import']);

    (new ProcessWhatsAppWebhookJob($store->id, [
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '111'],
            'messages' => [['id' => 'wamid.STOP', 'from' => '24177451288', 'timestamp' => '1790000000', 'type' => 'text', 'text' => ['body' => 'STOP']]],
        ]]]]],
    ]))->handle();

    expect($contact->fresh()->consent_status)->toBe('opt_out')
        ->and($contact->fresh()->consent_source)->toBe('whatsapp_stop');
});
