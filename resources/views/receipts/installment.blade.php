@php
    $money = fn ($v) => number_format((int) $v, 0, ',', ' ').' '.$currency;
    $count = $schedule->installments->count();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu {{ $schedule->label }} · tranche {{ $installment->number }}/{{ $count }}</title>
    <style>
        /* Reçu d'une tranche payée : A5, imprimable depuis le navigateur. */
        @page { size: A5; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f8fb; color: #0b2d4a; font-family: 'Outfit', 'DejaVu Sans', Arial, sans-serif; font-size: 13px; line-height: 1.5; }
        .sheet { max-width: 520px; margin: 24px auto; background: #fff; border: 1px solid #dbe6ef; border-radius: 14px; padding: 26px 28px; }
        .brand { font-weight: 800; font-size: 18px; }
        .brand i { display: inline-block; width: 20px; height: 20px; border-radius: 6px; background: linear-gradient(135deg, #00c896, #0e7bd6); vertical-align: -4px; margin-right: 6px; color: #fff; font-style: normal; font-size: 13px; text-align: center; line-height: 20px; }
        h1 { font-size: 20px; margin: 18px 0 2px; }
        .muted { color: #4a5d70; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td { padding: 7px 0; border-bottom: 1px solid #eef2f6; vertical-align: top; }
        td.v { text-align: right; font-weight: 600; }
        .total td { border-bottom: 0; font-size: 17px; font-weight: 800; color: #007a5c; }
        .foot { margin-top: 18px; font-size: 11.5px; }
        .print { margin-top: 18px; text-align: center; }
        .print button { border: 0; border-radius: 8px; background: #0e7bd6; color: #fff; padding: 8px 16px; font-size: 13px; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { border: 0; margin: 0; } .print { display: none; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="brand"><i>M</i>{{ $organization->name }}</div>
    <div class="muted">{{ $schedule->store->name }}</div>

    <h1>Reçu de paiement</h1>
    <div class="muted">N° {{ str_pad((string) $installment->id, 6, '0', STR_PAD_LEFT) }} · {{ $installment->paid_at->locale('fr')->translatedFormat('j F Y à H\hi') }}</div>

    <table>
        <tr><td>Reçu de</td><td class="v">{{ $schedule->customer->name }}</td></tr>
        @if ($schedule->beneficiary)
            <tr><td>Pour</td><td class="v">{{ $schedule->beneficiary->name }}</td></tr>
        @endif
        <tr><td>Objet</td><td class="v">{{ $installment->label ?? $schedule->label }}</td></tr>
        <tr><td>Tranche</td><td class="v">{{ $installment->number }} sur {{ $count }} · échéance du {{ $installment->due_on->locale('fr')->translatedFormat('j F Y') }}</td></tr>
        @if ($method)
            <tr><td>Moyen de paiement</td><td class="v">{{ $method }}</td></tr>
        @endif
        <tr class="total"><td>Montant reçu</td><td class="v">{{ $money($installment->amount) }}</td></tr>
    </table>

    <p class="foot muted">
        Déjà réglé sur cet échéancier : {{ $money($paidTotal) }} sur {{ $money($paidTotal + $remaining) }}.
        @if ($remaining > 0)
            Reste à régler : {{ $money($remaining) }}.
        @else
            Échéancier soldé. Merci !
        @endif
    </p>

    <div class="print"><button type="button" onclick="window.print()">Imprimer</button></div>
</div>
</body>
</html>
