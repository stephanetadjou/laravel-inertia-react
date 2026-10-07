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
    /** Séparateurs CSV acceptés ; Excel en français enregistre avec « ; ». */
    public const DELIMITERS = [',', ';', "\t"];

    /**
     * @param  string|null  $delimiter  séparateur CSV imposé, sinon détecté sur la première ligne
     * @return array<int, array<int, mixed>>
     */
    public static function readRows(UploadedFile $file, ?string $delimiter = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return in_array($extension, ['xlsx', 'xls'], true)
            ? self::readExcelRows($file, $extension)
            : self::readCsvRows($file, $delimiter);
    }

    /**
     * Séparateur le plus fréquent de la première ligne (hors guillemets
     * approximativement : suffisant pour une ligne d'en-têtes).
     */
    public static function detectDelimiter(string $firstLine): string
    {
        $counts = [];

        foreach (self::DELIMITERS as $delimiter) {
            $counts[$delimiter] = substr_count($firstLine, $delimiter);
        }

        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    private static function readCsvRows(UploadedFile $file, ?string $delimiter): array
    {
        $content = (string) file_get_contents($file->getRealPath());

        // BOM UTF-8 d'Excel, et fichiers enregistrés en Windows-1252 (accents).
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = in_array($delimiter, self::DELIMITERS, true) ? $delimiter : self::detectDelimiter($firstLine);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
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
