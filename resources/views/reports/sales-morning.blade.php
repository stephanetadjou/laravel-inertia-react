<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Vos relances du jour</title></head>
<body style="margin:0;background:#f4f8fb;font-family:Arial,sans-serif;color:#0b2d4a;">
<div style="max-width:560px;margin:0 auto;padding:24px;">
    <p style="font-weight:800;font-size:18px;margin:0 0 4px;">{{ $organization->name }}</p>
    <h1 style="font-size:20px;margin:0 0 6px;">Bonjour {{ strtok($user->name, ' ') }}, vos relances du jour</h1>
    <p style="color:#4a5d70;margin:0 0 16px;">{{ $rows->count() }} prospect(s) attendent un message. Touchez « WhatsApp » pour ouvrir le message prérempli sur votre téléphone.</p>
    @foreach ($rows as $row)
        <div style="background:#fff;border:1px solid #dbe6ef;border-radius:10px;padding:12px 14px;margin-bottom:8px;">
            <b>{{ $row['name'] }}</b><br>
            <span style="color:#4a5d70;font-size:13px;">{{ $row['reason'] }}</span><br>
            <a href="{{ $row['whatsapp_url'] }}" style="display:inline-block;margin-top:8px;background:#00c896;color:#fff;text-decoration:none;border-radius:8px;padding:6px 12px;font-size:13px;">WhatsApp</a>
        </div>
    @endforeach
    <p style="color:#4a5d70;font-size:12px;margin-top:16px;">Après l’envoi, marquez la relance « Fait » dans « À faire » : le prospect sort de la liste.</p>
</div>
</body>
</html>
