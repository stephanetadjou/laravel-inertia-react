@php
    $money = fn ($v) => number_format((int) $v, 0, ',', ' ').' FCFA';
    $c = $booking->customer;
    $v = $booking->vehicle;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Contrat de location n° {{ $booking->id }}</title>
    <style>
        @page { size: A4; margin: 16mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #2B1A3D; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 12.5px; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #E8590C; }
        .muted { color: #6B5E78; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 4px; vertical-align: top; }
        td.k { width: 38%; color: #6B5E78; }
        .box { border: 1px solid #ddd; border-radius: 6px; padding: 8px; }
        .sign td { height: 70px; border: 1px solid #ccc; width: 50%; }
        ul { margin: 0; padding-left: 16px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td><h1>Contrat de location de véhicule</h1><span class="muted">N° {{ $booking->id }} · établi le {{ now()->format('d/m/Y') }}</span></td>
            <td style="text-align:right"><b>{{ $booking->store?->name ?? $booking->organization->name }}</b><br><span class="muted">{{ $booking->store?->city }}</span></td>
        </tr>
    </table>

    <h2>Les parties</h2>
    <table>
        <tr><td class="k">Loueur</td><td>{{ $booking->organization->name }} ({{ $booking->store?->name }})</td></tr>
        <tr><td class="k">Locataire</td><td>{{ $c?->company_name ?: $c?->name }} · {{ $c?->phone_e164 ? '+'.$c->phone_e164 : '' }}</td></tr>
        <tr><td class="k">Pièce d’identité / permis n°</td><td>…………………………………………</td></tr>
    </table>

    <h2>Le véhicule et la période</h2>
    <table>
        <tr><td class="k">Véhicule</td><td>{{ $v?->label() }}</td></tr>
        <tr><td class="k">Du</td><td>{{ $booking->starts_at->format('d/m/Y à H\hi') }} @if($booking->pickup_place) · prise en charge : {{ $booking->pickup_place }} @endif</td></tr>
        <tr><td class="k">Au</td><td>{{ $booking->ends_at->format('d/m/Y à H\hi') }} ({{ $days }} jour{{ $days > 1 ? 's' : '' }})</td></tr>
        @if($booking->driver_name)<tr><td class="k">Chauffeur</td><td>{{ $booking->driver_name }}</td></tr>@endif
        <tr><td class="k">Kilométrage au départ</td><td>{{ $booking->km_out ? number_format($booking->km_out, 0, ',', ' ').' km' : '…………… km' }} · carburant : {{ $booking->fuel_out !== null ? $booking->fuel_out.' %' : '……' }}</td></tr>
    </table>

    <h2>Prix, caution et kilomètres</h2>
    <table>
        <tr><td class="k">Prix de la location</td><td><b>{{ $money($booking->amount) }}</b> @if($booking->quote) (devis {{ $booking->quote->number }}) @endif</td></tr>
        <tr><td class="k">Caution</td><td>{{ $booking->deposit ? $money($booking->deposit).', versée au départ et rendue au retour après état des lieux' : 'Aucune' }}</td></tr>
        <tr><td class="k">Kilomètres inclus</td><td>{{ $booking->km_included ? number_format($booking->km_included, 0, ',', ' ').' km' : 'Illimités' }}</td></tr>
        @if($booking->km_included && ($settings['extra_km_price'] ?? 0) > 0)
            <tr><td class="k">Kilomètre en plus</td><td>{{ $money($settings['extra_km_price']) }} par km au-delà du forfait, facturé au retour</td></tr>
        @endif
        <tr><td class="k">Retard</td><td>Chaque jour de retard est facturé au tarif journalier ({{ $money($booking->rate) }})</td></tr>
    </table>

    @if(! empty($settings['accessories']))
        <h2>Accessoires remis au départ</h2>
        <div class="box">@foreach($settings['accessories'] as $a) ☐ {{ $a }} &nbsp;&nbsp; @endforeach</div>
    @endif

    <h2>Conditions</h2>
    <div class="box">{!! nl2br(e($settings['conditions'] ?? '')) !!}</div>

    <h2>Signatures</h2>
    <table class="sign">
        <tr><td>Le loueur<br><span class="muted">Nom, date, signature</span></td><td>Le locataire<br><span class="muted">« Lu et approuvé », date, signature</span></td></tr>
    </table>
</body>
</html>
