@php
    $money = fn ($v) => number_format((int) $v, 0, ',', ' ').' FCFA';
    $sold = $vehicle->status === 'sold';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $vehicle->name }} à vendre · {{ $organization?->name }}</title>
    <meta property="og:title" content="{{ $vehicle->name }} à vendre · {{ $money($vehicle->sale_price) }}">
    @if ($photos)
        <meta property="og:image" content="{{ $photos[0] }}">
    @endif
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f2f5f9; color: #2b1a3d; font-family: 'Outfit', Arial, sans-serif; font-size: 15px; line-height: 1.5; }
        .card { max-width: 720px; margin: 20px auto; background: #fff; border: 1px solid #dde5ee; border-radius: 16px; overflow: hidden; }
        .photos { display: flex; gap: 6px; overflow-x: auto; scroll-snap-type: x mandatory; background: #2b1a3d; }
        .photos img { height: 280px; max-width: 92%; object-fit: cover; scroll-snap-align: start; flex-shrink: 0; }
        .body { padding: 20px 22px 26px; }
        h1 { margin: 0 0 4px; font-size: 24px; }
        .price { font-size: 26px; font-weight: 800; color: #e8590c; }
        .muted { color: #5a6b7d; }
        .sold { display: inline-block; padding: 4px 12px; border-radius: 999px; background: #e5e7eb; font-weight: 700; }
        .wa { display: block; margin-top: 18px; padding: 14px; border-radius: 12px; background: #16a34a; color: #fff; text-align: center; font-weight: 700; text-decoration: none; }
        p.desc { white-space: pre-line; }
    </style>
</head>
<body>
<div class="card">
    @if ($photos)
        <div class="photos">
            @foreach ($photos as $photo)
                <img src="{{ $photo }}" alt="{{ $vehicle->name }}">
            @endforeach
        </div>
    @endif
    <div class="body">
        <h1>{{ $vehicle->name }}</h1>
        <div class="muted">
            {{ \App\Models\BookableResource::CATEGORIES[$vehicle->category] ?? '' }}
            @if ($vehicle->mileage) · {{ number_format($vehicle->mileage, 0, ',', ' ') }} km @endif
            @if ($vehicle->store?->city) · {{ $vehicle->store->city }} @endif
        </div>
        <p>
            @if ($sold)
                <span class="sold">Vendu</span>
            @else
                <span class="price">{{ $money($vehicle->sale_price) }}</span>
            @endif
        </p>
        @if ($description)
            <p class="desc">{{ $description }}</p>
        @endif
        <p class="muted">Paiement en plusieurs fois possible, reprise de votre véhicule étudiée.</p>
        @if ($whatsapp)
            <a class="wa" href="{{ $whatsapp }}">Écrire sur WhatsApp à {{ $vehicle->store?->name ?? $organization?->name }}</a>
        @endif
    </div>
</div>
</body>
</html>
