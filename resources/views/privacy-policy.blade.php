<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Politique de confidentialité — {{ $company }}</title>
    <style>
        :root { color-scheme: light dark; --text: #1f2937; --muted: #6b7280; --bg: #ffffff; --line: #e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --text: #e5e7eb; --muted: #9ca3af; --bg: #111827; --line: #374151; } }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 40px 16px 64px; }
        h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 4px; }
        h2 { font-size: 1.15rem; margin: 32px 0 8px; padding-top: 16px; border-top: 1px solid var(--line); }
        p, li { margin: 0 0 8px; }
        .muted { color: var(--muted); font-size: .9rem; }
        a { color: inherit; }
    </style>
</head>
<body>
<main>
    <h1>Politique de confidentialité</h1>
    <p class="muted">{{ $company }} · mise à jour le {{ $organization->updated_at?->format('d/m/Y') }}</p>

    <p>
        Cette politique explique comment <strong>{{ $company }}</strong>
        @if ($stores->isNotEmpty())
            (établissements : {{ $stores->implode(', ') }})
        @endif
        utilise les données personnelles de ses clients dans le cadre de ses échanges sur WhatsApp.
    </p>

    <h2>1. Responsable du traitement</h2>
    <p>
        {{ $company }}
        @if ($organization->privacy_address)<br>{{ $organization->privacy_address }}@endif
        @if ($organization->privacy_email)<br>Courriel : <a href="mailto:{{ $organization->privacy_email }}">{{ $organization->privacy_email }}</a>@endif
        @if ($organization->privacy_phone)<br>Téléphone : {{ $organization->privacy_phone }}@endif
    </p>

    <h2>2. Données collectées</h2>
    <ul>
        <li>Identité et contact : nom, numéro de téléphone WhatsApp, et le cas échéant date de naissance et sexe.</li>
        <li>Relation commerciale : établissement fréquenté, historique d'achats (date, montant, catégorie).</li>
        <li>Consentement : votre accord ou refus de recevoir nos messages WhatsApp, sa date et la façon dont il a été recueilli.</li>
        <li>Messages : les messages que vous nous envoyez sur WhatsApp et l'état de remise et de lecture des messages que nous vous envoyons.</li>
    </ul>

    <h2>3. Utilisation des données</h2>
    <ul>
        <li>Vous répondre et assurer le suivi de vos achats et de vos demandes.</li>
        <li>Vous envoyer des informations, nouveautés et offres sur WhatsApp, <strong>uniquement si vous l'avez accepté</strong>.</li>
        <li>Mesurer l'efficacité de nos messages (nombre de messages remis et lus).</li>
    </ul>
    <p>Vos données ne sont ni vendues ni louées à des tiers.</p>

    <h2>4. Base légale</h2>
    <p>
        L'envoi de messages promotionnels repose sur votre consentement, que vous pouvez retirer à tout moment.
        Le suivi de vos achats et de vos demandes repose sur notre relation commerciale.
    </p>

    <h2>5. Destinataires et prestataires</h2>
    <p>
        Les données sont accessibles aux seules personnes habilitées de {{ $company }}. Les messages transitent par la
        plateforme WhatsApp Business de Meta Platforms, qui agit en tant que prestataire pour leur acheminement. Les données
        sont hébergées par notre prestataire d'hébergement informatique, qui ne les utilise pas pour son propre compte.
    </p>

    <h2>6. Durée de conservation</h2>
    <p>
        Les données sont conservées pendant toute la durée de notre relation commerciale, puis au plus trois ans après
        votre dernier contact ou achat, sauf obligation légale de conservation plus longue.
    </p>

    <h2>7. Vos droits</h2>
    <p>
        Vous pouvez à tout moment demander l'accès à vos données, leur rectification ou leur suppression, et vous opposer
        à leur utilisation
        @if ($organization->privacy_email)
            en écrivant à <a href="mailto:{{ $organization->privacy_email }}">{{ $organization->privacy_email }}</a>.
        @else
            en nous contactant dans l'un de nos établissements.
        @endif
    </p>
    <p>
        Pour ne plus recevoir nos messages WhatsApp, répondez simplement <strong>STOP</strong> à l'un d'eux : votre
        désinscription est prise en compte automatiquement.
    </p>

    @if ($organization->privacy_extra)
        <h2>8. Informations complémentaires</h2>
        <p>{!! nl2br(e($organization->privacy_extra)) !!}</p>
    @endif
</main>
</body>
</html>
