<?php

namespace App\Mail;

use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email du rapport quotidien : l'essentiel et les chiffres clés dans le
 * corps du message, le rapport complet en pièce jointe PDF quand la
 * bibliothèque PDF est installée, sinon en lien vers la page du rapport.
 */
class DailyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $report */
    public function __construct(public Organization $organization, public array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Rapport du {$this->report['day_label']} · {$this->organization->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'reports.mail', with: [
            'report' => $this->report,
            'url' => route('reports.daily', ['date' => $this->report['day']]),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        // PDF joint seulement si barryvdh/laravel-dompdf est installé.
        if (! class_exists(Pdf::class)) {
            return [];
        }

        $pdf = Pdf::loadView('reports.daily', ['report' => $this->report])->setPaper('a4');

        return [Attachment::fromData(fn () => $pdf->output(), "rapport-{$this->report['day']}.pdf")->withMime('application/pdf')];
    }
}
