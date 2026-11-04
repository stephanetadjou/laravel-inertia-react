<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Rapports PDF du mois écoulé (Paramètres › Rapports). */
class MonthlyReportMail extends Mailable
{
    /** @param array<string, string> $files nom du fichier => contenu PDF */
    public function __construct(public Organization $organization, public string $period, public array $files) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Rapport mensuel {$this->period} · {$this->organization->name}");
    }

    public function content(): Content
    {
        return new Content(htmlString: "<p>Bonjour,</p><p>Voici les rapports de {$this->period} pour ".e($this->organization->name).'.</p><p>Menentia</p>');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return collect($this->files)
            ->map(fn (string $content, string $name) => Attachment::fromData(fn () => $content, $name)->withMime('application/pdf'))
            ->values()->all();
    }
}
