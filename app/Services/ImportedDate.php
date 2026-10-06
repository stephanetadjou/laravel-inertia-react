<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Lecture d'une date venant d'un fichier importé (CSV ou Excel) : date
 * d'achat, date de naissance.
 *
 * Formats acceptés : 2026-03-15, 15/03/2026, 15-03-2026, 15.03.2026,
 * 15/03/26, 2026/03/15, et le numéro de série Excel (ex. 46096) quand la
 * cellule n'est pas au format date dans le classeur. Sans ce traitement,
 * Laravel lisait 46096 comme un timestamp Unix : l'achat tombait le 01/01/1970.
 *
 * Renvoie la date au format Y-m-d, ou null si elle est illisible ou
 * invraisemblable (avant $minYear ou dans le futur).
 */
class ImportedDate
{
    private const FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'Y/m/d'];

    /** Numéro de série Excel du 31/12/2099 : au-delà, ce n'est pas une date. */
    private const EXCEL_SERIAL_MAX = 73050;

    public static function parse(mixed $value, int $minYear = 2000): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $date = null;

        if (preg_match('/^\d{1,5}(\.\d+)?$/', $raw) === 1) {
            // Un nombre seul ne peut être qu'un numéro de série Excel.
            if ((float) $raw < 1 || (float) $raw > self::EXCEL_SERIAL_MAX) {
                return null;
            }

            $date = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw));
        }

        // « 2026-03-15 00:00:00 » : seule la partie date compte.
        $datePart = explode(' ', $raw)[0];

        foreach (self::FORMATS as $format) {
            if ($date !== null) {
                break;
            }

            try {
                $candidate = Carbon::createFromFormat('!'.$format, $datePart);
            } catch (Throwable) {
                continue;
            }

            // Aller-retour strict : refuse 31/02 ou « 15/03/2026 » lu en d/m/y.
            if ($candidate !== false && $candidate->format($format) === $datePart) {
                $date = $candidate;
            }
        }

        if ($date === null) {
            try {
                $date = Carbon::parse($raw);
            } catch (Throwable) {
                return null;
            }
        }

        if ($date->year < $minYear || $date->isAfter(now()->endOfDay())) {
            return null;
        }

        return $date->toDateString();
    }
}
