<?php

// Augmenter le temps d'exécution maximal du script pour les listes importantes
set_time_limit(300);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ==========================================
// CONFIGURATION DE L'API WHATSAPP CLOUD API
// ==========================================
$accessToken  = 'EAAp1ZBb46DVcBSpkags9Qd6pgZBHYs6sUvOvmSx6fEqXIsfJM8DB5AjYS1wnZCidZBH6BZAZAWgEztVqIWA67TUqNrwYhvmiQrV2F8E9rA4iVqbkfX5o8yHCe8kc3aUT9aTO8qBqG8rwCFxoxXsZAKKwYuG3hwyFlh6g3Rx2wDgKZAbc6gqFbq8DWaYTIMEnnpTeioARBHe0zi1D04MhYk7drFZBEOMOEgeZB36JaKZCWS1BxO6Ky2EZBFbFJPZBJZC1GNjXw4Jn0oBdwPzmtn2cyE2MLoYgZDZD';
$phoneNumberId = '1367106629815417';
#$templateName  = 'nom_de_votre_template_avec_variable'; // Ex: 'notification_employe'
#$languageCode  = 'fr'; // Code langue de votre template

$templateName = 'hello_world';
$languageCode = 'en_US';

$csvFile = 'employes.csv';

// Vérification de l'existence du fichier
if (!file_exists($csvFile)) {
    die("Erreur : Le fichier {$csvFile} est introuvable dans le dossier.\n");
}

// Ouverture et lecture du fichier CSV
if (($handle = fopen($csvFile, 'r')) !== FALSE) {
    
    // Lire la première ligne (les en-têtes)
    $headers = fgetcsv($handle, 1000, ",");
    
    $rowNumber = 1;
    $successCount = 0;
    $errorCount = 0;

    echo "=== Début de l'envoi des messages WhatsApp ===\n\n";

    // Parcourir chaque ligne du fichier
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $rowNumber++;
        
        // Associer les données aux colonnes (Nom = index 0, Telephone = index 1)
        $nom = trim($data[0]);
        $telephone = trim($data[1]);

        // Nettoyage basique du numéro (suppression des espaces, du + ou tirets éventuels)
        $telephone = preg_replace('/[^0-9]/', '', $telephone);

        if (empty($telephone) || empty($nom)) {
            echo "Ligne {$rowNumber} ignorée : Données invalides (Nom ou Téléphone manquant).\n";
            continue;
        }

        // Construction de la charge utile (Payload) pour l'API WhatsApp
        // Exemple avec un template comportant 1 variable textuelle (le nom) dans le corps du message
        $payloadData = [
            'messaging_product' => 'whatsapp',
            'to' => $telephone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $nom // Remplace la variable {{1}} dans votre template
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // URL de l'API Graph Meta
        $url = "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages";

        // Exécution de la requête cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Analyse de la réponse
        if ($httpCode === 200) {
            $successCount++;
            echo "[SUCCÈS] Message envoyé à {$nom} ({$telephone})\n";
        } else {
            $errorCount++;
            echo "[ERREUR] Échec pour {$nom} ({$telephone}) - Code HTTP {$httpCode}\n";
            // Vous pouvez logger la réponse de l'API pour déboguer : echo $response . "\n";
        }

        // =====================================================================
        // NORME / RESPECT DES LIMITES WHATSAPP (Rate Limiting & Anti-Spam)
        // =====================================================================
        // Pause d'une demi-seconde (500 000 microsecondes) entre chaque message
        // Ajustez ce délai en fonction de votre niveau de palier (Tier) Meta.
        usleep(500000); 
    }

    fclose($handle);

    echo "\n=== Fin des envois ===\n";
    echo "Succès : {$successCount}\n";
    echo "Erreurs : {$errorCount}\n";

} else {
    echo "Erreur lors de l'ouverture du fichier CSV.\n";
}

?>