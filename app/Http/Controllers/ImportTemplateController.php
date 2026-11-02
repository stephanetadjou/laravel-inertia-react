<?php

namespace App\Http\Controllers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Modèles Excel téléchargeables pour chaque import : en-têtes reconnus
 * automatiquement, deux lignes d'exemple et un onglet « Aide » qui explique
 * chaque colonne. Une entreprise remplit le modèle et l'importe seule.
 */
class ImportTemplateController extends Controller
{
    /** @var array<string, array{file: string, columns: list<array{0: string, 1: string}>, rows: list<list<string>>}> */
    public const TEMPLATES = [
        'clients' => [
            'file' => 'modele-import-clients',
            'columns' => [
                ['Nom', 'Obligatoire. Prénom et nom du client.'],
                ['Téléphone', 'Obligatoire. Numéro WhatsApp, avec ou sans indicatif (ex. 077 12 34 56).'],
                ['Consentement WhatsApp', 'Facultatif. « oui » si le client accepte de recevoir vos messages.'],
                ['Date de naissance', 'Facultatif. JJ/MM/AAAA.'],
                ['Sexe', 'Facultatif. F ou H.'],
            ],
            'rows' => [['Aïcha Mba', '077 12 34 56', 'oui', '14/02/1990', 'F'], ['Paul Obame', '066 98 76 54', 'non', '', 'H']],
        ],
        'adherents' => [
            'file' => 'modele-import-personnes-suivies',
            'columns' => [
                ['Responsable', 'Obligatoire. Nom du parent, tuteur ou employeur : c’est lui qui reçoit les messages.'],
                ['Téléphone du responsable', 'Obligatoire. Numéro WhatsApp du responsable.'],
                ['Personne suivie', 'Obligatoire. Nom de l’enfant, de l’élève ou du salarié.'],
                ['Date de naissance', 'Facultatif. JJ/MM/AAAA.'],
                ['Lien', 'Facultatif. parent, tuteur, employeur ou autre (parent par défaut).'],
                ['Cours', 'Facultatif. Nom exact d’un cours déjà créé : la personne y est inscrite.'],
            ],
            'rows' => [['Mireille Mba', '077 00 11 22', 'Léo Mba', '01/03/2017', 'parent', 'Judo 6-9 ans'], ['Mireille Mba', '077 00 11 22', 'Inès Mba', '12/06/2015', 'parent', 'Danse']],
        ],
        'ventes' => [
            'file' => 'modele-import-ventes',
            'columns' => [
                ['Date', 'Obligatoire. JJ/MM/AAAA.'],
                ['Point de vente', 'Obligatoire. Nom ou code caisse du point de vente (ou choisi au dépôt).'],
                ['Ticket', 'Facultatif. Numéro de ticket ou de facture : évite les doublons.'],
                ['Téléphone', 'Facultatif. Rattache la vente à la fiche du client ; sans numéro, vente anonyme.'],
                ['Nom', 'Facultatif. Nom du client.'],
                ['Famille', 'Famille ou catégorie de l’article (ou famille par défaut choisie à l’import).'],
                ['Article', 'Facultatif. Libellé de l’article.'],
                ['Quantité', 'Facultatif. 1 par défaut.'],
                ['Prix unitaire', 'Facultatif si le montant est donné.'],
                ['Montant', 'Montant de la ligne en FCFA (négatif pour un retour).'],
            ],
            'rows' => [['01/10/2026', 'BOUT01', 'T0001', '077 12 34 56', 'Aïcha Mba', 'Chaussures', 'Baskets running', '1', '35000', '35000'], ['01/10/2026', 'BOUT01', 'T0002', '', '', 'Accessoires', 'Casquette', '2', '5000', '10000']],
        ],
    ];

    public function show(string $kind): StreamedResponse
    {
        abort_unless(isset(self::TEMPLATES[$kind]), 404);
        $template = self::TEMPLATES[$kind];

        return response()->streamDownload(function () use ($template) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('À remplir');
            $sheet->fromArray([array_column($template['columns'], 0), ...$template['rows']], null, 'A1', true);
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            foreach (range(1, count($template['columns'])) as $i) {
                $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
            }

            $help = $book->createSheet()->setTitle('Aide');
            $help->fromArray([['Colonne', 'À quoi elle sert'], ...$template['columns'], [], ['Conseil', 'Remplacez les lignes d’exemple par vos données, gardez la première ligne, puis déposez le fichier dans Menentia.']], null, 'A1', true);
            $help->getStyle('1:1')->getFont()->setBold(true);
            $help->getColumnDimension('A')->setAutoSize(true);
            $help->getColumnDimension('B')->setWidth(90);

            (new Xlsx($book))->save('php://output');
        }, $template['file'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
