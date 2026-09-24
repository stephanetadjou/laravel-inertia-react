<?php

$accessToken  = 'EAAp1ZBb46DVcBStEemlfJc1ZAxKYs4OoinTxTNHqDyZAGMOY5aHZA9UyOeaDpjnBhmPy5TZCuEXOj9ROqcFNCEuPyvyDYW8hFU9K8KeeY34rwSmfos7MX2C1S2Y2OFtH5yisoIuDWm83lhnqgWSEaQnGZAAWu9oYOlMGZAm19hoScZA1VWBELmsghnv0DTH7VrCepYGjiqewtHGLAc7p9J9CoupZCIbiAKxy6qoCzjG5JDzrlkKAofQFgu4LpUA51cAtdZCZBqbwMMucrdapyoPmgcGVnYZD';

//$accessToken = 'EAAp1ZBb46DVcBSsml8ciVq6aMWWJRQEHnvc0yTIVu4t7i3FhsIV8KTYvAJEWbIHdbHC2ryAiZCH6cAzxeTrPPr3L0LLHg59SZCLgtUl8R07wJOVJu4lnoTXpiZA2A580ZBNZAgQf7tnSfgQZCunh9XRCnhrym9Wz3jO7bZAFqyZA7IMBmF7mlvoChbCNKLbDvPwZDZD'
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

echo "Début de l'envoi...\n";
echo "URL : {$url}\n";

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);

$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo "\n========== RESULTAT ==========\n";
echo "HTTP Code : " . $httpCode . "\n";
echo "cURL Error Number : " . $curlErrno . "\n";
echo "cURL Error : " . ($curlError ?: 'Aucune') . "\n";
echo "Response :\n";
echo $response . "\n";
echo "==============================\n";
