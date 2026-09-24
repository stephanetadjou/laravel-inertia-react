<?php

$accessToken  = 'EAAp1ZBb46DVcBSpkags9Qd6pgZBHYs6sUvOvmSx6fEqXIsfJM8DB5AjYS1wnZCidZBH6BZAZAWgEztVqIWA67TUqNrwYhvmiQrV2F8E9rA4iVqbkfX5o8yHCe8kc3aUT9aTO8qBqG8rwCFxoxXsZAKKwYuG3hwyFlh6g3Rx2wDgKZAbc6gqFbq8DWaYTIMEnnpTeioARBHe0zi1D04MhYk7drFZBEOMOEgeZB36JaKZCWS1BxO6Ky2EZBFbFJPZBJZC1GNjXw4Jn0oBdwPzmtn2cyE2MLoYgZDZD';
$phoneNumberId = '1367106629815417';
$recipientPhone = '24162605054';

$url = "https://graph.facebook.com/v25.0/{$phoneNumberId}/messages";

$data = [
    'messaging_product' => 'whatsapp',
    'to' => $recipientPhone,
    'type' => 'template',
    'template' => [
        'name' => 'hello_world',
        'language' => [
            'code' => 'en_US'
        ]
    ]
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo "Erreur cURL : " . curl_error($ch);
} else {
    echo "HTTP Code : " . $httpCode . PHP_EOL;
    echo "Réponse Meta :" . PHP_EOL;
    echo json_encode(
        json_decode($response, true),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}

curl_close($ch);