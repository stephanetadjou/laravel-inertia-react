<?php

// ==========================================
// ENVOI WHATSAPP EN MASSE DEPUIS UN FICHIER CSV
// Basé sur le script d'envoi unitaire (whatsapp single)
// ==========================================

set_time_limit(0); // pas de limite : le nombre de contacts peut varier
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------------
// Configuration
// ------------------------------------------

// Le token ne doit JAMAIS être écrit en dur dans le code source.
// Définissez-le avant l'exécution, par exemple :
//   set META_WHATSAPP_TOKEN=votre_token   (Windows)
//   export META_WHATSAPP_TOKEN=votre_token (Linux/Mac)
$accessToken = getenv('META_WHATSAPP_TOKEN');
if (!$accessToken) {
    die("Erreur : la variable d'environnement META_WHATSAPP_TOKEN n'est pas définie.\n");
}

$phoneNumberId = getenv('META_PHONE_NUMBER_ID') ?: '1367106629815417';
$apiVersion    = 'v25.0';

$templateName  = 'hello_world';
$languageCode  = 'en_US';

// Indicatif pays utilisé pour compléter les numéros locaux (Gabon = 241)
$defaultCountryCode = '241';

// Pause entre deux envois, en microsecondes (500 000 = 0,5 s)
$delayBetweenSends = 500000;

// ------------------------------------------
// Fichier CSV externe (passé en argument, sinon valeur par défaut)
// Usage : php whatsapp_bulk.php chemin/vers/contacts.csv
// Colonnes attendues, sans en-tête obligatoire : Nom, Telephone
// ------------------------------------------
$csvFile = $argv[1] ?? 'contacts.csv';

if (!file_exists($csvFile)) {
    die("Erreur : le fichier {$csvFile} est introuvable.\n");
}

// Fichier de log des échecs, pour analyse ultérieure (réponse API complète)
$errorLogFile = 'whatsapp_bulk_errors.log';

// ------------------------------------------
// Fonctions
// ------------------------------------------

function normalizePhone(string $raw, string $defaultCountryCode): string
{
    $digits = preg_replace('/[^0-9]/', '', $raw);

    // Numéro local gabonais à 8 chiffres sans indicatif : on le complète
    if (strlen($digits) === 8) {
        $digits = $defaultCountryCode . $digits;
    }

    return $digits;
}

function sendWhatsAppTemplate(
    string $url,
    string $accessToken,
    string $phone,
    string $templateName,
    string $languageCode,
    string $variableText
): array {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'template',
        'template' => [
            'name' => $templateName,
            'language' => [
                'code' => $languageCode,
            ],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $variableText,
                        ],
                    ],
                ],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'curlErrno' => $curlErrno,
        'curlError' => $curlError,
        'response' => $response,
    ];
}

// ------------------------------------------
// Envoi en masse
// ------------------------------------------

$url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

$handle = fopen($csvFile, 'r');
if ($handle === false) {
    die("Erreur lors de l'ouverture du fichier CSV.\n");
}

// Ignore la ligne d'en-tête (Nom,Telephone)
fgetcsv($handle, 1000, ',');

$rowNumber = 1;
$successCount = 0;
$errorCount = 0;
$skippedCount = 0;

$errorLog = fopen($errorLogFile, 'a');

echo "=== Début de l'envoi en masse ===\n";
echo "Fichier source : {$csvFile}\n";
echo "Template : {$templateName} ({$languageCode})\n\n";

while (($data = fgetcsv($handle, 1000, ',')) !== false) {
    $rowNumber++;

    $nom = trim($data[0] ?? '');
    $telephoneBrut = trim($data[1] ?? '');

    if (empty($nom) || empty($telephoneBrut)) {
        echo "Ligne {$rowNumber} ignorée : Nom ou Téléphone manquant.\n";
        $skippedCount++;
        continue;
    }

    $telephone = normalizePhone($telephoneBrut, $defaultCountryCode);

    if (strlen($telephone) < 10) {
        echo "Ligne {$rowNumber} ignorée : numéro invalide après normalisation ({$telephoneBrut} -> {$telephone}).\n";
        $skippedCount++;
        continue;
    }

    $result = sendWhatsAppTemplate($url, $accessToken, $telephone, $templateName, $languageCode, $nom);

    if ($result['httpCode'] === 200) {
        $successCount++;
        echo "[SUCCÈS] {$nom} ({$telephone})\n";
    } else {
        $errorCount++;
        echo "[ERREUR] {$nom} ({$telephone}) - HTTP {$result['httpCode']}";
        if ($result['curlError']) {
            echo " - cURL: {$result['curlError']}";
        }
        echo "\n";

        fwrite($errorLog, sprintf(
            "[%s] Ligne %d | %s (%s) | HTTP %d | cURL errno %d: %s | Réponse: %s\n",
            date('Y-m-d H:i:s'),
            $rowNumber,
            $nom,
            $telephone,
            $result['httpCode'],
            $result['curlErrno'],
            $result['curlError'] ?: 'aucune',
            $result['response'] ?: 'vide'
        ));
    }

    // Respect du rate-limit WhatsApp
    usleep($delayBetweenSends);
}

fclose($handle);
fclose($errorLog);

echo "\n=== Fin des envois ===\n";
echo "Succès  : {$successCount}\n";
echo "Erreurs : {$errorCount}\n";
echo "Ignorés : {$skippedCount}\n";
if ($errorCount > 0) {
    echo "Détail des erreurs consigné dans : {$errorLogFile}\n";
}
