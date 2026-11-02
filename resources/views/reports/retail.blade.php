@php
    $k = $direction['kpis'];
    $p = $direction['previous'];
    $num = fn ($v) => is_numeric($v) ? number_format((float) $v, 0, ',', ' ') : $v;
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ').' '.$currency;
    $delta = function ($cur, $prev) {
        if (! $prev) {
            return '';
        }
        $d = round(($cur - $prev) / abs($prev) * 100);

        return '<span class="'.($d >= 0 ? 'ok' : 'bad').'">'.($d >= 0 ? '+' : '').$d.' %</span>';
    };
    $maxMonth = max(1, collect($direction['monthly'])->max('net'));
    $table = function (array $t, int $limit = 15) use ($num) {
        $html = '<table class="data"><thead><tr>';
        foreach ($t['columns'] as $i => $c) {
            $html .= '<th'.($i > 0 ? ' class="r"' : '').'>'.e($c).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($t['rows'], 0, $limit) as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $v) {
                $html .= '<td'.($i > 0 ? ' class="r"' : '').'>'.e($num($v)).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport de direction · {{ $organization->name }}</title>
    <style>
        /* Rapport de direction Menentia Retail : A4, tableaux simples (compatible PDF). */
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f8fb; color: #0b2d4a; font-family: 'Outfit', 'DejaVu Sans', Arial, sans-serif; font-size: 11px; line-height: 1.45; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; background: #fff; }
        .top { background: #0b2d4a; color: #fff; padding: 22px 34px 20px; }
        .brand { font-weight: 800; font-size: 18px; }
        .brand i { display: inline-block; width: 20px; height: 20px; border-radius: 6px; background: #00c896; background: linear-gradient(135deg, #00c896, #0e7bd6); vertical-align: -4px; margin-right: 6px; color: #fff; font-style: normal; font-size: 13px; text-align: center; line-height: 20px; }
        .top h1 { margin: 12px 0 2px; font-size: 22px; font-weight: 800; }
        .top .sub { color: #7fe3c7; font-weight: 600; }
        .body { padding: 18px 34px 40px; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 -8px; }
        .kpis td { width: 25%; border: 1px solid #dbe6ef; border-radius: 10px; padding: 9px 11px; vertical-align: top; }
        .kl { font-size: 9px; font-weight: 700; color: #4a5d70; text-transform: uppercase; letter-spacing: 0.4px; }
        .kv { font-size: 17px; font-weight: 800; margin: 2px 0; }
        .ok { color: #007a5c; }
        .bad { color: #c2410c; }
        .muted { color: #4a5d70; }
        h2 { font-size: 13px; margin: 18px 0 8px; }
        h2:before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #00c896; margin-right: 8px; vertical-align: 1px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; color: #4a5d70; border-bottom: 1.5px solid #0b2d4a; padding: 5px 6px; }
        table.data td { border-bottom: 1px solid #e6eef5; padding: 5px 6px; }
        table.data .r { text-align: right; }
        .bars { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .bars td { vertical-align: bottom; text-align: center; padding: 0 3px; height: 110px; }
        .bar { background: #0e7bd6; border-radius: 4px 4px 0 0; margin: 0 auto; width: 70%; }
        .bars .lbl td { height: auto; font-size: 8.5px; color: #4a5d70; padding-top: 4px; }
        .foot { color: #4a5d70; font-size: 9.5px; margin-top: 18px; border-top: 1px solid #e6eef5; padding-top: 8px; }
        @media print { body { background: #fff; } }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
<div class="sheet">
    <div class="top">
        <div class="brand"><i>M</i>Menentia · Analyse commerciale</div>
        <h1>Rapport de direction</h1>
        <div class="sub">{{ $organization->name }} · {{ $scopeLabel }} · du {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d/m/Y') }}</div>
    </div>
    <div class="body">
        <table class="kpis">
            <tr>
                <td><div class="kl">CA net</div><div class="kv">{{ $money($k['net']) }}</div><div class="muted">{!! $delta($k['net'], $p['net']) !!} vs période précédente</div></td>
                <td><div class="kl">Tickets</div><div class="kv">{{ $num($k['tickets']) }}</div><div class="muted">{!! $delta($k['tickets'], $p['tickets']) !!}</div></td>
                <td><div class="kl">Panier moyen</div><div class="kv">{{ $money($k['basket']) }}</div><div class="muted">{!! $delta($k['basket'], $p['basket']) !!}</div></td>
                <td><div class="kl">Unités vendues</div><div class="kv">{{ $num($k['units']) }}</div></td>
            </tr>
            <tr>
                <td><div class="kl">Clients identifiés</div><div class="kv">{{ $num($k['customers']) }}</div><div class="muted">{{ $k['identification_rate'] }} % des tickets</div></td>
                <td><div class="kl">Nouveaux clients</div><div class="kv">{{ $num($k['new_customers']) }}</div></td>
                <td><div class="kl">Clients actifs ({{ $direction['active_days'] }} j)</div><div class="kv">{{ $num($k['active_customers']) }}</div></td>
                <td><div class="kl">Retours · remises</div><div class="kv">{{ $money($k['returns']) }}</div><div class="muted">remises {{ $money($k['discounts']) }}</div></td>
            </tr>
        </table>

        @if (count($direction['monthly']) > 1)
            <h2>Évolution mensuelle du CA net</h2>
            <table class="bars">
                <tr>
                    @foreach ($direction['monthly'] as $m)
                        <td><div class="bar" style="height: {{ max(2, round(max(0, $m['net']) / $maxMonth * 100)) }}px"></div></td>
                    @endforeach
                </tr>
                <tr class="lbl">
                    @foreach ($direction['monthly'] as $m)
                        <td>{{ \App\Services\RetailAnalytics::monthLabel($m['month']) }}</td>
                    @endforeach
                </tr>
            </table>
        @endif

        @foreach ($network['tables'] as $t)
            <h2>{{ $t['title'] }}</h2>
            {!! $table($t, 12) !!}
        @endforeach

        <h2>{{ $families['tables'][0]['title'] }}</h2>
        {!! $table($families['tables'][0], 12) !!}

        <h2>Rétention</h2>
        <p>Taux de réachat : <b>{{ $retention['kpis']['repeat_rate'] }} %</b> des clients identifiés de la période ont acheté au moins deux jours différents.
            @if ($retention['kpis']['average_interval'] !== null) Délai moyen entre deux achats : <b>{{ $retention['kpis']['average_interval'] }} jours</b>. @endif</p>
        {!! $table($retention['tables'][0], 12) !!}

        <div class="foot">CA net = ventes − retours − remises, ventes anonymes comprises. Panier moyen = CA des ventes ÷ tickets. Édité le {{ now()->format('d/m/Y à H:i') }} par Menentia.</div>
    </div>
</div>
</body>
</html>
