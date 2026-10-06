<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Services\CustomerLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Répare les achats importés avant la correction d'ImportedDate : une date
 * Excel non formatée (ex. 46096) était lue comme un timestamp Unix et
 * l'achat tombait le 01/01/1970 à 12:48:16. Le nombre de secondes depuis le
 * 01/01/1970 est donc le numéro de série Excel d'origine.
 *
 * Sans --force, la commande affiche seulement ce qu'elle corrigerait.
 */
class RepairPurchaseDates extends Command
{
    protected $signature = 'crm:repair-purchase-dates {--force : Enregistre les corrections}';

    protected $description = 'Corrige les achats datés du 01/01/1970 (date Excel mal lue à l\'import)';

    public function handle(CustomerLifecycle $lifecycle): int
    {
        $timezone = (string) config('app.timezone');
        $broken = Purchase::with('contact:id,customer_id')->where('purchased_at', '<', '1971-01-01')->get();

        if ($broken->isEmpty()) {
            $this->info('Aucun achat daté de 1970 : rien à corriger.');

            return self::SUCCESS;
        }

        $rows = [];
        $fixes = [];

        foreach ($broken as $purchase) {
            $raw = (string) $purchase->getRawOriginal('purchased_at');
            $serial = Carbon::parse($raw, $timezone)->getTimestamp();
            $fixed = $serial >= 1 && $serial <= 73050
                ? Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString()
                : null;

            $rows[] = [$purchase->id, $raw, $serial, $fixed ?? 'non réparable'];

            if ($fixed !== null) {
                $fixes[$purchase->id] = $fixed;
            }
        }

        $this->table(['Achat', 'Date enregistrée', 'Série Excel', 'Date corrigée'], $rows);

        if (! $this->option('force')) {
            $this->warn(count($fixes).' achat(s) réparable(s). Relancez avec --force pour enregistrer.');

            return self::SUCCESS;
        }

        CustomerLifecycle::batch(function () use ($fixes) {
            foreach ($fixes as $id => $date) {
                Purchase::whereKey($id)->first()?->update(['purchased_at' => $date]);
            }
        });

        $this->info(count($fixes).' achat(s) corrigé(s), fiches clients recalculées.');

        return self::SUCCESS;
    }
}
