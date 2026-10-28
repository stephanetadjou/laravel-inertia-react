@php
    use App\Services\DailyReport as R;
    $k = $report['kpis'];
    $money = fn ($v) => R::money($v);
    $num = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $maxSeries = max(1, collect($report['series'])->max('revenue'));
    $maxService = max(1, collect($report['services'])->max('revenue'));
    $md = fn ($text) => preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', e($text));
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport du {{ $report['day_label'] }} · {{ $report['organization'] }}</title>
    <style>
        /* Rapport quotidien au dirigeant : A4, mise en page en tableaux (compatible PDF). */
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f8fb; color: #0b2d4a; font-family: 'Outfit', 'DejaVu Sans', Arial, sans-serif; font-size: 11.5px; line-height: 1.45; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; background: #fff; page-break-after: always; position: relative; }
        .sheet:last-child { page-break-after: auto; }
        .top { background: #0b2d4a; color: #fff; padding: 22px 34px 20px; }
        .top table { width: 100%; border-collapse: collapse; }
        .brand { font-weight: 800; font-size: 19px; letter-spacing: -0.3px; }
        .brand i { display: inline-block; width: 20px; height: 20px; border-radius: 6px; background: #00c896; background: linear-gradient(135deg, #00c896, #0e7bd6); vertical-align: -4px; margin-right: 6px; color: #fff; font-style: normal; font-size: 13px; text-align: center; line-height: 20px; }
        .top h1 { margin: 14px 0 2px; font-size: 24px; font-weight: 800; }
        .top .sub { color: #7fe3c7; font-weight: 600; }
        .top .meta { text-align: right; color: #c8d6e3; font-size: 11px; vertical-align: bottom; }
        .body { padding: 18px 34px 50px; }
        .essential { border-left: 4px solid #0e7bd6; background: #eaf4fd; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; }
        .essential b { color: #0a66b2; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 -8px; }
        .kpis td { width: 33.33%; border: 1px solid #dbe6ef; border-radius: 10px; padding: 10px 12px; vertical-align: top; }
        .kl { font-size: 9.5px; font-weight: 700; color: #4a5d70; text-transform: uppercase; letter-spacing: 0.4px; }
        .kv { font-size: 20px; font-weight: 800; margin: 2px 0; }
        .ok { color: #007a5c; }
        .bad { color: #c2410c; }
        .muted { color: #4a5d70; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        h2:before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #00c896; margin-right: 8px; vertical-align: 1px; }
        h2 small { float: right; font-size: 10px; font-weight: 600; color: #4a5d70; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.3px; color: #4a5d70; border-bottom: 1.5px solid #0b2d4a; padding: 5px 6px; }
        table.data td { border-bottom: 1px solid #e6eef5; padding: 6px; }
        table.data .r { text-align: right; }
        table.data tr.total td { font-weight: 700; border-bottom: 0; }
        .bars { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .bars td { vertical-align: bottom; text-align: center; padding: 0 3px; height: 120px; }
        .bar { background: #a8d1f5; border-radius: 4px 4px 0 0; margin: 0 auto; width: 70%; }
        .bar.current { background: #0e7bd6; }
        .bars .lbl td { height: auto; font-size: 9.5px; color: #4a5d70; padding-top: 4px; vertical-align: top; }
        .bars .lbl td.current { color: #0a66b2; font-weight: 700; }
        .bars .closed { font-size: 9px; color: #8aa0b4; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 6px -8px 0; }
        .cards td { width: 50%; border: 1px solid #dbe6ef; border-radius: 10px; padding: 12px; vertical-align: top; }
        .progress { height: 9px; background: #e6eef5; border-radius: 9px; position: relative; margin: 8px 0; }
        .progress i { position: absolute; left: 0; top: 0; bottom: 0; background: #00c896; border-radius: 9px; }
        .progress em { position: absolute; top: -3px; width: 3px; height: 15px; background: #0b2d4a; }
        .mini { height: 7px; background: #e6eef5; border-radius: 7px; position: relative; width: 100%; }
        .mini i { position: absolute; left: 0; top: 0; bottom: 0; background: #0e7bd6; border-radius: 7px; }
        .two { width: 100%; border-collapse: separate; border-spacing: 14px 0; margin: 0 -14px; }
        .two > tbody > tr > td { width: 50%; vertical-align: top; }
        .tag { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 9.5px; font-weight: 700; }
        .tag.vip { background: #0b2d4a; color: #fff; }
        .tag.high { background: #fde4dc; color: #c2410c; }
        .tag.medium { background: #fbf0d9; color: #a4680b; }
        .actions { list-style: none; margin: 0; padding: 0; }
        .actions li { border-bottom: 1px solid #e6eef5; padding: 7px 0 7px 30px; position: relative; }
        .actions li span { position: absolute; left: 0; top: 6px; width: 20px; height: 20px; border-radius: 50%; background: #0e7bd6; color: #fff; text-align: center; line-height: 20px; font-weight: 700; font-size: 10.5px; }
        .foot { position: absolute; bottom: 14px; left: 34px; right: 34px; border-top: 1px solid #dbe6ef; padding-top: 6px; font-size: 9.5px; color: #4a5d70; }
        .foot span { float: right; }
        .empty { color: #8aa0b4; font-style: italic; padding: 6px 0; }
        @media screen { body { padding: 20px 0; } .sheet { box-shadow: 0 4px 24px rgba(11, 45, 74, 0.12); margin-bottom: 20px; } }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; margin: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="top">
        <table>
            <tr>
                <td>
                    <div class="brand"><i>m</i>menentia</div>
                    <h1>Rapport du {{ $report['day_label'] }}</h1>
                    <div class="sub">{{ $report['organization'] }} · {{ count($report['stores']) }} établissement{{ count($report['stores']) > 1 ? 's' : '' }}{{ count($report['stores']) <= 3 ? ' · '.implode(', ', $report['stores']) : '' }}</div>
                </td>
                <td class="meta">Destinataire : Direction générale<br>Généré le {{ now()->locale('fr')->translatedFormat('j F à H\hi') }}</td>
            </tr>
        </table>
    </div>

    <div class="body">
        <div class="essential"><b>L’essentiel.</b> {{ $report['summary'] }}</div>

        <table class="kpis">
            <tr>
                <td>
                    <div class="kl">Chiffre d’affaires</div>
                    <div class="kv">{{ $money($k['revenue']) }}</div>
                    <div class="{{ ($k['revenue_vs_last_week'] ?? 0) >= 0 ? 'ok' : 'bad' }}">{{ R::percent($k['revenue_vs_previous_day']) }} vs {{ $k['previous_day_label'] }} · {{ R::percent($k['revenue_vs_last_week']) }} vs {{ $k['last_week_label'] }}</div>
                </td>
                <td>
                    <div class="kl">Rendez-vous honorés</div>
                    <div class="kv">{{ $k['done'] }} / {{ $k['planned'] }}</div>
                    <div class="muted">{{ $k['done_rate'] }} % · {{ $k['cancelled'] }} annulé{{ $k['cancelled'] > 1 ? 's' : '' }}</div>
                </td>
                <td>
                    <div class="kl">Absences (no-show)</div>
                    <div class="kv">{{ $k['no_shows'] }}</div>
                    <div class="{{ $k['no_show_rate'] > $k['no_show_target'] ? 'bad' : 'ok' }}">{{ number_format($k['no_show_rate'], 1, ',', ' ') }} % · objectif &lt; {{ (int) $k['no_show_target'] }} %</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="kl">Panier moyen</div>
                    <div class="kv">{{ $money($k['average_basket']) }}</div>
                    <div class="muted">{{ $k['sales'] }} vente{{ $k['sales'] > 1 ? 's' : '' }}</div>
                </td>
                <td>
                    <div class="kl">Nouveaux clients</div>
                    <div class="kv">{{ $k['new_customers'] }}</div>
                    <div class="ok">dont {{ $k['new_online'] }} via la réservation en ligne</div>
                </td>
                <td>
                    <div class="kl">Clients récupérés</div>
                    <div class="kv">{{ $k['recovered'] }}</div>
                    <div class="ok">revenus après relance · {{ $money($k['recovered_amount']) }}</div>
                </td>
            </tr>
        </table>

        <h2>Chiffre d’affaires des 14 derniers jours <small>en milliers de FCFA</small></h2>
        <table class="bars">
            <tr>
                @foreach ($report['series'] as $d)
                    <td>
                        @if ($d['closed'])
                            <div class="closed">fermé</div>
                        @else
                            @if ($d['current'])<div style="font-weight:700;color:#0a66b2;font-size:10px">{{ $num(round($d['revenue'] / 1000)) }} k</div>@endif
                            <div class="bar {{ $d['current'] ? 'current' : '' }}" style="height: {{ max(2, round($d['revenue'] / $maxSeries * 100)) }}px"></div>
                        @endif
                    </td>
                @endforeach
            </tr>
            <tr class="lbl">
                @foreach ($report['series'] as $d)
                    <td class="{{ $d['current'] ? 'current' : '' }}">{{ $d['label'] }}<br>{{ $d['number'] }}</td>
                @endforeach
            </tr>
        </table>

        <table class="cards">
            <tr>
                <td>
                    @if ($report['target'])
                        @php $t = $report['target']; @endphp
                        <div class="kl">Objectif de {{ $t['month'] }}</div>
                        <div class="kv">{{ $num($t['done']) }} <span class="muted" style="font-size:11px;font-weight:500">/ {{ $money($t['goal']) }}</span></div>
                        <div class="progress"><i style="width: {{ min(100, $t['percent']) }}%"></i><em style="left: {{ min(100, $t['expected_percent']) }}%"></em></div>
                        <div class="muted">{{ number_format($t['percent'], 1, ',', ' ') }} % atteints ; le trait marque le rythme attendu ({{ number_format($t['expected_percent'], 1, ',', ' ') }} %).
                            @if ($t['remaining'] > 0) Il reste {{ $money($t['remaining']) }} à faire en {{ $t['remaining_days'] }} jour{{ $t['remaining_days'] > 1 ? 's' : '' }}, soit {{ $num($t['per_day']) }} par jour. @else Objectif atteint. @endif
                        </div>
                    @else
                        <div class="kl">Objectif du mois</div>
                        <div class="muted" style="margin-top:6px">Aucun objectif fixé. Renseignez-le dans Paramètres › Rapports pour suivre le rythme du mois.</div>
                    @endif
                </td>
                <td>
                    @php $tm = $report['tomorrow']; @endphp
                    <div class="kl">Demain, {{ $tm['label'] }}</div>
                    <div class="kv">{{ $tm['count'] }} rendez-vous @if ($tm['fill_rate'] !== null)<span class="muted" style="font-size:11px;font-weight:500">· agenda rempli à {{ $tm['fill_rate'] }} %</span>@endif</div>
                    <div class="muted">{{ $tm['confirmed'] }} confirmé{{ $tm['confirmed'] > 1 ? 's' : '' }}, <b class="bad">{{ $tm['to_confirm'] }} à confirmer</b> (liste « Rendez-vous à rappeler »).
                        @if ($tm['expected_revenue'] > 0) CA prévu : {{ $money($tm['expected_revenue']) }}. @endif
                        {{ $tm['free_slots'] }} créneau{{ $tm['free_slots'] > 1 ? 'x' : '' }} libre{{ $tm['free_slots'] > 1 ? 's' : '' }}.</div>
                </td>
            </tr>
        </table>

        <h2>Par établissement</h2>
        @if (count($report['by_store']) === 0)
            <div class="empty">Aucune activité enregistrée.</div>
        @else
            <table class="data">
                <tr><th>Établissement</th><th class="r">Prévus</th><th class="r">Honorés</th><th class="r">Absents</th><th class="r">Annulés</th><th class="r">Prestations</th><th class="r">Produits</th><th class="r">CA</th></tr>
                @foreach ($report['by_store'] as $s)
                    <tr><td>{{ $s['name'] }}</td><td class="r">{{ $s['planned'] }}</td><td class="r">{{ $s['done'] }}</td><td class="r">{{ $s['no_shows'] }}</td><td class="r">{{ $s['cancelled'] }}</td><td class="r">{{ $money($s['services']) }}</td><td class="r">{{ $money($s['products']) }}</td><td class="r"><b>{{ $money($s['revenue']) }}</b></td></tr>
                @endforeach
                @if (count($report['by_store']) > 1)
                    @php $c = collect($report['by_store']); @endphp
                    <tr class="total"><td>Total</td><td class="r">{{ $c->sum('planned') }}</td><td class="r">{{ $c->sum('done') }}</td><td class="r">{{ $c->sum('no_shows') }}</td><td class="r">{{ $c->sum('cancelled') }}</td><td class="r">{{ $money($c->sum('services')) }}</td><td class="r">{{ $money($c->sum('products')) }}</td><td class="r">{{ $money($c->sum('revenue')) }}</td></tr>
                @endif
            </table>
        @endif

        <table class="two">
            <tr>
                <td>
                    <h2>Prestations du jour</h2>
                    @if (count($report['services']) === 0)
                        <div class="empty">Aucune prestation encaissée.</div>
                    @else
                        <table class="data">
                            <tr><th>Prestation</th><th class="r">Nb</th><th class="r">CA</th><th style="width:30%"></th></tr>
                            @foreach ($report['services'] as $s)
                                <tr><td>{{ $s['name'] }}</td><td class="r">{{ $s['count'] }}</td><td class="r">{{ $money($s['revenue']) }}</td><td><div class="mini"><i style="width: {{ round($s['revenue'] / $maxService * 100) }}%"></i></div></td></tr>
                            @endforeach
                        </table>
                    @endif
                </td>
                <td>
                    <h2>Moyens de paiement</h2>
                    @if (count($report['payments']) === 0)
                        <div class="empty">Aucun encaissement.</div>
                    @else
                        <table class="data">
                            <tr><th>Moyen</th><th class="r">Montant</th><th class="r">Part</th><th style="width:30%"></th></tr>
                            @foreach ($report['payments'] as $p)
                                <tr><td>{{ $p['label'] }}</td><td class="r">{{ $money($p['amount']) }}</td><td class="r">{{ $p['share'] }} %</td><td><div class="mini"><i style="width: {{ $p['share'] }}%; background:#0b2d4a"></i></div></td></tr>
                            @endforeach
                        </table>
                    @endif
                </td>
            </tr>
        </table>
    </div>
    <div class="foot">Menentia · Connaissez vos clients. Remplissez votre agenda.<span>page 1 / 2</span></div>
</div>

<div class="sheet">
    <div class="top" style="padding: 14px 34px">
        <table><tr><td><div class="brand"><i>m</i>menentia</div></td><td class="meta" style="vertical-align: middle">Rapport du {{ $report['day_label'] }} · {{ $report['organization'] }}</td></tr></table>
    </div>
    <div class="body">
        <h2>Clients à risque de départ <small>ont dépassé 1,5 à 2 fois leur rythme habituel</small></h2>
        @if ($report['at_risk']['count'] === 0)
            <div class="empty">Aucun client en alerte aujourd’hui.</div>
        @else
            <table class="data">
                <tr><th>Client</th><th>Segment RFM</th><th>Dernière visite</th><th>Rythme habituel</th><th class="r">CA 12 mois</th><th>Risque</th></tr>
                @foreach ($report['at_risk']['top'] as $c)
                    <tr><td>{{ $c['name'] }} @if ($c['vip'])<span class="tag vip">VIP</span>@endif</td><td>{{ $c['segment'] }}</td><td>{{ $c['last_visit'] ?? '—' }}</td><td>{{ $c['interval'] ? 'tous les '.$c['interval'].' j' : '—' }}</td><td class="r">{{ $money($c['spent_12m']) }}</td><td><span class="tag {{ $c['risk'] }}">{{ $c['risk'] === 'high' ? 'élevé' : 'moyen' }}</span></td></tr>
                @endforeach
            </table>
            <p class="muted">Ces {{ count($report['at_risk']['top']) }} clients pèsent {{ $money($report['at_risk']['top_spent']) }} sur 12 mois. La liste complète ({{ $report['at_risk']['count'] }} clients) est dans Menentia, rubrique Clients, filtre « Alerte churn ».</p>
        @endif

        <table class="two">
            <tr>
                <td>
                    <h2>Équipe</h2>
                    @if (count($report['team']) === 0)
                        <div class="empty">Aucun rendez-vous honoré attribué à un intervenant.</div>
                    @else
                        <table class="data">
                            <tr><th>Intervenant</th><th>Site</th><th class="r">RDV</th><th class="r">CA</th></tr>
                            @foreach ($report['team'] as $m)
                                <tr><td>{{ $m['name'] }}</td><td>{{ $m['store'] }}</td><td class="r">{{ $m['appointments'] }}</td><td class="r">{{ $money($m['revenue']) }}</td></tr>
                            @endforeach
                        </table>
                    @endif
                </td>
                <td>
                    @php $w = $report['week']; @endphp
                    <h2>Rythme de la semaine</h2>
                    <table class="data">
                        <tr><td>{{ $w['label'] }}</td><td class="r"><b>{{ $money($w['current']) }}</b></td></tr>
                        <tr><td>Même période semaine dernière</td><td class="r">{{ $money($w['previous']) }}</td></tr>
                        <tr><td>Évolution</td><td class="r {{ ($w['change'] ?? 0) >= 0 ? 'ok' : 'bad' }}"><b>{{ R::percent($w['change']) }}</b></td></tr>
                        <tr><td>Taux d’absence de la semaine</td><td class="r">{{ $w['no_show_rate'] === null ? '—' : number_format($w['no_show_rate'], 1, ',', ' ').' %' }}</td></tr>
                        <tr><td>Taux d’absence moyen sur 3 mois</td><td class="r">{{ $w['no_show_rate_3m'] === null ? '—' : number_format($w['no_show_rate_3m'], 1, ',', ' ').' %' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <h2>Absents du jour à reprogrammer</h2>
        @if (count($report['absents']) === 0)
            <div class="empty">Aucune absence aujourd’hui.</div>
        @else
            <table class="data">
                <tr><th>Client</th><th>Heure</th><th>Site</th><th>Prestation</th><th>Remarque</th></tr>
                @foreach ($report['absents'] as $a)
                    <tr><td>{{ $a['name'] }}</td><td>{{ $a['time'] }}</td><td>{{ $a['store'] }}</td><td>{{ $a['service'] ?? '—' }}</td><td class="{{ $a['count'] >= 3 ? 'bad' : '' }}">{{ $a['remark'] }}</td></tr>
                @endforeach
            </table>
        @endif

        <table class="two">
            <tr>
                <td>
                    @php $rel = $report['relationship']; @endphp
                    <h2>Relation client</h2>
                    <table class="data">
                        <tr><td>Réservations en ligne reçues</td><td class="r"><b>{{ $rel['online_bookings'] }}</b></td></tr>
                        <tr><td>Relances WhatsApp envoyées</td><td class="r"><b>{{ $rel['follow_ups'] }}</b></td></tr>
                        <tr><td>Clients revenus après une relance</td><td class="r"><b>{{ $rel['recovered'] }}</b></td></tr>
                        <tr><td>Nouveaux consentements WhatsApp</td><td class="r"><b>{{ $rel['new_consents'] }}</b></td></tr>
                        <tr><td>Désinscriptions (STOP)</td><td class="r"><b>{{ $rel['stops'] }}</b></td></tr>
                    </table>
                </td>
                <td>
                    @php $b = $report['base']; @endphp
                    <h2>Base clients</h2>
                    <table class="data">
                        <tr><td>Clients actifs (visite &lt; 90 jours)</td><td class="r"><b>{{ $num($b['active']) }}</b></td></tr>
                        <tr><td>Champions et fidèles</td><td class="r"><b>{{ $num($b['champions']) }}</b></td></tr>
                        <tr><td>VIP</td><td class="r"><b>{{ $num($b['vip']) }}</b> · {{ $b['vip_share'] }} % du CA</td></tr>
                        <tr><td>À risque</td><td class="r"><b>{{ $num($b['at_risk']) }}</b></td></tr>
                        <tr><td>Anniversaires cette semaine</td><td class="r"><b>{{ $num($b['birthdays']) }}</b></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <h2>Actions recommandées pour demain</h2>
        @if (count($report['actions']) === 0)
            <div class="empty">Rien d’urgent : bonne soirée.</div>
        @else
            <ol class="actions">
                @foreach ($report['actions'] as $i => $action)
                    <li><span>{{ $i + 1 }}</span>{!! $md($action) !!}</li>
                @endforeach
            </ol>
        @endif
    </div>
    <div class="foot">Généré automatiquement par Menentia chaque soir. Réglages : Paramètres › Rapports.<span>page 2 / 2</span></div>
</div>
@if (! empty($autoPrint))
    <script>window.addEventListener('load', () => window.print());</script>
@endif
</body>
</html>
