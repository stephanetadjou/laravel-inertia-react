<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PlatformLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Back-office plateforme › Demandes de démo reçues du site vitrine
 * (POST /api/public/v1/leads) : suivi commercial, note, export Excel.
 */
class LeadController extends Controller
{
    private const COLUMNS = ['Reçue le', 'Nom', 'Entreprise', 'WhatsApp', 'Ville', 'Activité', 'Message', 'Statut', 'Note', 'Contacté le'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Admin/Leads', [
            'filters' => $filters,
            'statuses' => collect(Lead::STATUSES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'counts' => Lead::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'leads' => $this->query($filters)->latest('id')->paginate(25)->withQueryString()->through(fn (Lead $lead) => [
                'id' => $lead->id,
                'created_at' => $lead->created_at?->toIso8601String(),
                'name' => $lead->name,
                'company' => $lead->company,
                'whatsapp' => $lead->whatsapp ?? $lead->whatsapp_raw,
                'city' => $lead->city,
                'activity' => $lead->activity,
                'message' => $lead->message,
                'status' => $lead->status,
                'note' => $lead->note,
                'contacted_at' => $lead->contacted_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Lead::STATUSES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $previous = $lead->status;
        $lead->fill(['status' => $data['status'], 'note' => $data['note'] ?? null]);
        if ($data['status'] !== 'new' && $lead->contacted_at === null) {
            $lead->contacted_at = now();
        }
        $lead->save();

        PlatformLog::record('lead.updated', "{$lead->company} : ".Lead::STATUSES[$data['status']], ['from' => $previous, 'to' => $data['status']], lead: $lead);

        return back()->with('success', 'Demande mise à jour.');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $leads = $this->query($filters)->latest('id')->get();

        PlatformLog::record('leads.exported', "{$leads->count()} demande(s)", array_filter($filters));

        return response()->streamDownload(function () use ($leads) {
            $data = [self::COLUMNS];
            foreach ($leads as $lead) {
                $data[] = [
                    $lead->created_at?->format('d/m/Y H:i'), $lead->name, $lead->company, $lead->whatsapp ?? $lead->whatsapp_raw,
                    $lead->city, $lead->activity, $lead->message, Lead::STATUSES[$lead->status] ?? $lead->status, $lead->note,
                    $lead->contacted_at?->format('d/m/Y H:i'),
                ];
            }
            $sheet = new Spreadsheet;
            $sheet->getActiveSheet()->setTitle('Demandes de démo')->fromArray($data, null, 'A1', true);
            (new Xlsx($sheet))->save('php://output');
        }, 'demandes-de-demo-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * @return array{q: ?string, status: ?string}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q')) ?: null,
            'status' => array_key_exists((string) $request->query('status'), Lead::STATUSES) ? (string) $request->query('status') : null,
        ];
    }

    private function query(array $filters): Builder
    {
        return Lead::query()
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['q'], fn (Builder $q, string $term) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                ->orWhere('company', 'like', "%{$term}%")->orWhere('whatsapp', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%")));
    }
}
