@php
    use App\Services\DailyReport as R;
    $k = $report['kpis'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<body style="margin:0;background:#f4f8fb;font-family:Arial,Helvetica,sans-serif;color:#0b2d4a;font-size:14px;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f8fb;padding:24px 0">
    <tr><td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">
            <tr><td style="background:#0b2d4a;color:#ffffff;padding:20px 24px">
                <div style="font-weight:800;font-size:18px">menentia</div>
                <div style="font-size:20px;font-weight:700;margin-top:8px">Rapport du {{ $report['day_label'] }}</div>
                <div style="color:#7fe3c7;font-size:13px">{{ $report['organization'] }}</div>
            </td></tr>
            <tr><td style="padding:20px 24px">
                <p style="margin:0 0 16px;padding:10px 14px;background:#eaf4fd;border-left:4px solid #0e7bd6;border-radius:6px"><b style="color:#0a66b2">L’essentiel.</b> {{ $report['summary'] }}</p>
                <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:13px">
                    <tr><td style="border-bottom:1px solid #e6eef5">Chiffre d’affaires</td><td align="right" style="border-bottom:1px solid #e6eef5"><b>{{ R::money($k['revenue']) }}</b> ({{ R::percent($k['revenue_vs_last_week']) }} vs {{ $k['last_week_label'] }})</td></tr>
                    <tr><td style="border-bottom:1px solid #e6eef5">Rendez-vous honorés</td><td align="right" style="border-bottom:1px solid #e6eef5"><b>{{ $k['done'] }} / {{ $k['planned'] }}</b></td></tr>
                    <tr><td style="border-bottom:1px solid #e6eef5">Absences</td><td align="right" style="border-bottom:1px solid #e6eef5"><b>{{ $k['no_shows'] }}</b> ({{ number_format($k['no_show_rate'], 1, ',', ' ') }} %)</td></tr>
                    <tr><td style="border-bottom:1px solid #e6eef5">Panier moyen</td><td align="right" style="border-bottom:1px solid #e6eef5"><b>{{ R::money($k['average_basket']) }}</b></td></tr>
                    <tr><td style="border-bottom:1px solid #e6eef5">Demain</td><td align="right" style="border-bottom:1px solid #e6eef5"><b>{{ $report['tomorrow']['count'] }} rendez-vous</b>, {{ $report['tomorrow']['to_confirm'] }} à confirmer</td></tr>
                    <tr><td>Clients à risque</td><td align="right"><b>{{ $report['at_risk']['count'] }}</b></td></tr>
                </table>
                @if (count($report['actions']) > 0)
                    <p style="margin:18px 0 6px;font-weight:700">Actions recommandées pour demain</p>
                    <ol style="margin:0;padding-left:20px">
                        @foreach ($report['actions'] as $action)
                            <li style="margin-bottom:6px">{!! preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', e($action)) !!}</li>
                        @endforeach
                    </ol>
                @endif
                <p style="margin:22px 0 0;text-align:center">
                    <a href="{{ $url }}" style="display:inline-block;background:#0e7bd6;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:700">Ouvrir le rapport complet</a>
                </p>
            </td></tr>
            <tr><td style="padding:12px 24px;color:#4a5d70;font-size:11px;border-top:1px solid #e6eef5">Généré automatiquement par Menentia chaque soir. Réglages : Paramètres › Rapports.</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
