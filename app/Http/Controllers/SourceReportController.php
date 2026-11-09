<?php

namespace App\Http\Controllers;

use App\Models\AdBudget;
use App\Models\Customer;
use App\Services\SourceReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Analyses › Sources : d'où viennent les clients et combien ils coûtent. */
class SourceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $months = SourceReport::months(6);

        return Inertia::render('Analytics/Sources', [
            'months' => $months,
            'sources' => collect(SourceReport::sources())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'rows' => (new SourceReport($organization))->rows($months),
            'currency' => $organization->currencySymbol(),
            'canEditBudgets' => $request->user()->canManageOrganization(),
        ]);
    }

    public function updateBudgets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'budgets' => ['required', 'array', 'max:100'],
            'budgets.*.source' => ['required', Rule::in(array_keys(Customer::SOURCES))],
            'budgets.*.month' => ['required', 'date_format:Y-m'],
            'budgets.*.amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        foreach ($data['budgets'] as $budget) {
            AdBudget::updateOrCreate(
                ['organization_id' => $request->user()->organization_id, 'source' => $budget['source'], 'month' => $budget['month']],
                ['amount' => (int) ($budget['amount'] ?? 0)],
            );
        }

        return back()->with('success', 'Budgets publicitaires enregistrés.');
    }
}
