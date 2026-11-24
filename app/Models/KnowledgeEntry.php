<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiche de la base de connaissances d'une entreprise (Assistant IA) : une
 * prestation, une règle, une question-réponse… issue du document de
 * l'entreprise ou apprise d'une réponse de l'équipe validée par le gérant.
 */
class KnowledgeEntry extends Model
{
    public const SECTIONS = [
        'presentation' => 'Présentation',
        'prestations' => 'Prestations et tarifs',
        'horaires' => 'Horaires et équipe',
        'regles' => 'Règles (réservation, annulation, paiement)',
        'faq' => 'Questions fréquentes',
        'interdit' => 'À ne pas dire / passer à un humain',
        'consignes' => 'Consignes internes (jamais envoyées au client)',
    ];

    protected $fillable = ['organization_id', 'section', 'title', 'content', 'keywords', 'external_id', 'source', 'active', 'embedding', 'embedding_model'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'embedding' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Texte cherché et indexé. */
    public function text(): string
    {
        return $this->title."\n".$this->content.($this->keywords ? "\n".$this->keywords : '');
    }
}
