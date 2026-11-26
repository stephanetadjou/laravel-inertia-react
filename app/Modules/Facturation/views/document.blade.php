@php
    $money = fn ($v) => number_format((int) $v, 0, ',', ' ').' FCFA';
    $date = fn ($d) => $d?->locale(app()->getLocale())->translatedFormat('j F Y');
    $status = $invoice->displayStatus();
    $canAnswer = ($public ?? false) && $invoice->isQuote() && in_array($invoice->status, ['draft', 'sent'], true) && $status !== 'expired';
    $phone = $invoice->store?->whatsapp_display_phone_number ?? $invoice->store?->booking_phone;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __($invoice->typeLabel()) }} {{ $invoice->number }} · {{ $invoice->organization->name }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f2f5f9; color: #2b1a3d; font-family: 'Outfit', 'DejaVu Sans', Arial, sans-serif; font-size: 13.5px; line-height: 1.5; }
        .sheet { max-width: 820px; margin: 24px auto; background: #fff; border: 1px solid #dde5ee; border-radius: 16px; padding: 34px 38px; box-shadow: 0 10px 30px rgba(11,31,58,.06); }
        .head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
        .org { font-weight: 800; font-size: 20px; }
        .muted { color: #5a6b7d; }
        .doc { text-align: right; }
        .doc h1 { margin: 0; font-size: 26px; letter-spacing: -.01em; color: #e8590c; }
        .pill { display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #eef2ff; color: #e8590c; }
        .pill.ok { background: #e6f8f1; color: #047857; } .pill.bad { background: #fdecec; color: #b42318; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 26px 0 18px; }
        .box { background: #f6f9fc; border-radius: 12px; padding: 14px 16px; }
        .box b { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #5a6b7d; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #5a6b7d; border-bottom: 2px solid #2b1a3d; padding: 8px 6px; }
        td { padding: 10px 6px; border-bottom: 1px solid #edf1f5; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: 300px; margin-top: 14px; }
        .totals td { border: 0; padding: 4px 6px; }
        .totals .grand td { font-size: 18px; font-weight: 800; border-top: 2px solid #2b1a3d; padding-top: 10px; }
        .notes { margin-top: 22px; white-space: pre-line; }
        .answer { margin-top: 26px; border: 2px solid #e8590c; border-radius: 14px; padding: 18px; }
        .answer input { width: 100%; padding: 10px 12px; border: 1px solid #c9d5e2; border-radius: 10px; font-size: 14px; margin: 8px 0 12px; }
        .btn { border: 0; border-radius: 10px; padding: 11px 18px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn.ok { background: #e8590c; color: #fff; } .btn.ghost { background: #eef2f6; color: #2b1a3d; }
        .flash { background: #e6f8f1; color: #047857; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 600; }
        .foot { margin-top: 28px; font-size: 11.5px; text-align: center; }
        .print { text-align: center; margin: 0 0 30px; }
        @media (max-width: 600px) { .sheet { margin: 0; border-radius: 0; padding: 22px 18px; } .parties { grid-template-columns: 1fr; } .doc { text-align: left; } .totals { width: 100%; } }
        @media print { body { background: #fff; } .sheet { border: 0; box-shadow: none; margin: 0; } .print, .answer { display: none; } }
    </style>
</head>
<body>
<div class="sheet">
    @if (session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif
    <div class="head">
        <div>
            <div class="org">{{ $invoice->organization->name }}</div>
            <div class="muted">{{ $invoice->store?->name }}@if ($phone) · {{ $phone }}@endif</div>
        </div>
        <div class="doc">
            <h1>{{ __($invoice->typeLabel()) }}</h1>
            <div><b>{{ $invoice->number }}</b></div>
            <div class="muted">{{ __('Émis le :date', ['date' => $date($invoice->issued_on)]) }}</div>
            <span class="pill {{ in_array($status, ['accepted', 'paid', 'invoiced'], true) ? 'ok' : (in_array($status, ['overdue', 'refused', 'expired', 'cancelled'], true) ? 'bad' : '') }}">{{ __($invoice->statusLabel()) }}</span>
        </div>
    </div>

    <div class="parties">
        <div class="box">
            <b>{{ __('Pour') }}</b>
            <div style="font-weight:600">{{ $invoice->customer->name }}</div>
            @if ($invoice->customer->phone_e164)<div class="muted">{{ $invoice->customer->phone_e164 }}</div>@endif
        </div>
        <div class="box">
            <b>{{ $invoice->isQuote() ? __('Validité') : __('Échéance') }}</b>
            @if ($invoice->isQuote())
                {{ __('Valable jusqu’au :date', ['date' => $date($invoice->valid_until)]) }}
            @else
                {{ __('À régler avant le :date', ['date' => $date($invoice->due_on)]) }}
            @endif
            @if ($invoice->title)<div class="muted">{{ $invoice->title }}</div>@endif
        </div>
    </div>

    <table>
        <thead><tr><th>{{ __('Désignation') }}</th><th class="num">{{ __('Qté') }}</th><th class="num">{{ __('Prix unitaire') }}</th><th class="num">{{ __('Montant') }}</th></tr></thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line->label }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($line->quantity, 2, ',', ' '), '0'), ',') }}</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $money($line->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if ($invoice->discount_amount > 0)
            <tr><td>{{ __('Sous-total') }}</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
            <tr><td>{{ $invoice->kind === 'balance' ? __('Remise et acomptes') : __('Remise') }}</td><td class="num">− {{ $money($invoice->discount_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $money($invoice->total) }}</td></tr>
        @if (! $invoice->isQuote() && $invoice->paid_amount > 0)
            <tr><td>{{ __('Déjà payé') }}</td><td class="num">{{ $money($invoice->paid_amount) }}</td></tr>
            <tr><td><b>{{ __('Reste à payer') }}</b></td><td class="num"><b>{{ $money($invoice->balance()) }}</b></td></tr>
        @endif
    </table>

    @if ($invoice->notes)
        <div class="notes muted">{{ $invoice->notes }}</div>
    @endif

    @if ($invoice->isQuote() && $invoice->accepted_at)
        <p class="notes"><b>{{ __('Accepté') }}</b> {{ __('le :date', ['date' => $date($invoice->accepted_at)]) }}@if ($invoice->accepted_by_name) {{ __('par :name', ['name' => $invoice->accepted_by_name]) }}@endif.</p>
    @endif

    @if ($canAnswer)
        <div class="answer">
            <b>{{ __('Ce devis vous convient ?') }}</b>
            <form method="post" action="{{ url('/d/'.$invoice->token.'/accept') }}">
                @csrf
                <label class="muted" for="name">{{ __('Votre nom, pour signer votre accord') }}</label>
                <input id="name" name="name" required maxlength="255" value="{{ $invoice->customer->name }}">
                <button class="btn ok" type="submit">{{ __('J’accepte le devis') }}</button>
            </form>
            <form method="post" action="{{ url('/d/'.$invoice->token.'/refuse') }}" style="margin-top:8px">
                @csrf
                <button class="btn ghost" type="submit">{{ __('Non merci') }}</button>
            </form>
        </div>
    @endif

    <p class="foot muted">{{ __('Document émis avec ClientlyX CRM') }}</p>
</div>
<div class="print"><button class="btn ghost" onclick="window.print()">{{ __('Imprimer ou enregistrer en PDF') }}</button></div>
</body>
</html>
