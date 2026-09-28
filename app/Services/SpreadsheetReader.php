<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Lit un fichier CSV ou Excel (xlsx/xls) uploadé et le rend sous forme de
 * lignes brutes (tableaux de cellules, première ligne = en-tête), partagé
 * par tous les imports de l'application (contacts, achats...).
 */
class SpreadsheetReader
{
    /**
     * @return array<int, array<int, mixed>>
     */
    public static function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return in_array($extension, ['xlsx', 'xls'], true)
            ? self::readExcelRows($file, $extension)
            : self::readCsvRows($file);
    }

    private static function readCsvRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $rows = [];

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private static function readExcelRows(UploadedFile $file, string $extension): array
    {
        $reader = $extension === 'xls' ? new Xls : new Xlsx;
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($file->getRealPath());

        // formatData=true : les cellules date/nombre sont converties en texte
        // affiché, pour rester compatibles avec le même traitement que le CSV.
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }
}
