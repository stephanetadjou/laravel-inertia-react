<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Audit;
use App\Services\BeneficiaryImport;
use App\Services\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Libre-service : fermer la liste « Pour bien démarrer », importer ses
 * personnes suivies depuis le modèle Excel.
 */
class SelfServiceController extends Controller
{
    public function dismissOnboarding(Request $request): RedirectResponse
    {
        $request->user()->organization->update(['onboarding_dismissed_at' => now()]);

        return back();
    }

    public function importBeneficiaries(Request $request, BeneficiaryImport $import): RedirectResponse
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
        ]);

        $report = $import->import(SpreadsheetReader::readRows($data['file']), Store::findOrFail($data['store_id']));
        Audit::record('import.beneficiaries', null, "Import de personnes suivies : {$report['created']} créée(s), {$report['enrolled']} inscription(s)", $report, $user);

        $message = "{$report['created']} personne(s) suivie(s) ajoutée(s), {$report['existing']} déjà connue(s), {$report['enrolled']} inscription(s) à un cours.";

        return back()
            ->with($report['errors'] === [] ? 'success' : 'error', $message.($report['errors'] === [] ? '' : ' À corriger : '.implode(' ', array_slice($report['errors'], 0, 5)).(count($report['errors']) > 5 ? ' (…)' : '')));
    }
}
