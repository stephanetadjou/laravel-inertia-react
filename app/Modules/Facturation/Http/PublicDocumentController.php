<?php

namespace App\Modules\Facturation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Facturation\Services\Invoicing;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Page publique d'un devis ou d'une facture (lien envoyé par WhatsApp). */
class PublicDocumentController extends Controller
{
    public function show(string $token): View
    {
        $invoice = $this->find($token);

        if ($invoice->viewed_at === null) {
            $invoice->update(['viewed_at' => now()]);
        }

        return view('facturation::document', ['invoice' => $invoice, 'public' => true]);
    }

    public function accept(Request $request, string $token, Invoicing $invoicing): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $invoicing->accept($this->find($token), $data['name']);

        return back()->with('success', 'Merci ! Votre accord a bien été transmis.');
    }

    public function refuse(string $token, Invoicing $invoicing): RedirectResponse
    {
        $invoicing->refuse($this->find($token));

        return back()->with('success', 'C’est noté. N’hésitez pas à nous écrire pour l’ajuster.');
    }

    private function find(string $token): Invoice
    {
        $invoice = Invoice::where('token', $token)->with(['lines', 'customer', 'store', 'organization', 'payments'])->first();
        abort_if($invoice === null || $invoice->status === 'cancelled' || ! $invoice->organization->hasModule('factures'), 404);

        // Document vu par le client : dans la langue de l'entreprise, sauf choix du visiteur (?lang=).
        if (! session()->has('locale')) {
            Locale::apply($invoice->organization->locale ?? Locale::DEFAULT);
        }

        return $invoice;
    }
}
