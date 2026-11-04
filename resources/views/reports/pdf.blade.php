<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 92px 40px 56px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #0b2d4a; }
    .head { position: fixed; top: -72px; left: 0; right: 0; height: 56px; border-bottom: 2px solid #0e7bd6; }
    .head img { position: absolute; left: 0; top: 2px; width: 40px; height: 40px; }
    .head .names { position: absolute; left: 52px; top: 2px; }
    .head .report { font-size: 14px; font-weight: bold; }
    .head .sub { color: #51657a; font-size: 9.5px; margin-top: 2px; }
    .head .org { position: absolute; right: 0; top: 6px; font-weight: bold; font-size: 10px; text-align: right; }
    h2 { font-size: 12px; color: #0a66b2; margin: 16px 0 6px; page-break-after: avoid; }
    h3 { font-size: 10px; margin: 10px 0 4px; page-break-after: avoid; }
    .note { color: #51657a; margin: 0 0 6px; }
    .section { page-break-inside: auto; }
    table.kpis { width: 100%; border-collapse: separate; border-spacing: 4px; margin: 0 -4px; }
    table.kpis td { width: 25%; padding: 7px 8px; background: #f4f8fb; border-radius: 4px; vertical-align: top; }
    .kpi-label { color: #51657a; font-size: 8.5px; }
    .kpi-value { font-size: 12px; font-weight: bold; margin-top: 2px; }
    .kpi-change { color: #51657a; font-size: 7.5px; margin-top: 2px; }
    table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.data th { text-align: left; font-size: 8.5px; color: #51657a; border-bottom: 1px solid #b9c7d4; padding: 4px 5px; }
    table.data td { border-bottom: 1px solid #e6ecf2; padding: 4px 5px; }
    table.data tr { page-break-inside: avoid; }
    .num { text-align: right; }
    .empty { color: #51657a; }
</style>
</head>
<body>
<div class="head">
    <img src="{{ $logo }}" alt="">
    <div class="names">
        <div class="report">{{ $title }}</div>
        <div class="sub">{{ $subtitle }}</div>
    </div>
    <div class="org">{{ $organization->name }}</div>
</div>

@forelse ($sections as $section)
    <div class="section">
        <h2>{{ $section['title'] }}</h2>
        @if (! empty($section['note']))
            <p class="note">{{ $section['note'] }}</p>
        @endif

        @if (! empty($section['kpis']))
            <table class="kpis">
                @foreach (array_chunk($section['kpis'], 4) as $row)
                    <tr>
                        @foreach ($row as $kpi)
                            <td>
                                <div class="kpi-label">{{ $kpi['label'] }}</div>
                                <div class="kpi-value">{{ $kpi['value'] }}</div>
                                @if (! empty($kpi['change']))
                                    <div class="kpi-change">{{ $kpi['change'] }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif

        @foreach ($section['tables'] ?? [] as $table)
            @if (! empty($table['title']))
                <h3>{{ $table['title'] }}</h3>
            @endif
            @if ($table['rows'] === [])
                <p class="empty">Aucune donnée.</p>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            @foreach ($table['columns'] as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['rows'] as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td class="{{ is_int($cell) ? 'num' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', ' ') : $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    </div>
@empty
    <p class="empty">Aucune donnée sur cette période.</p>
@endforelse
</body>
</html>
