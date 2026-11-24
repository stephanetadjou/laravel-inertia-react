<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Question d'un client restée sans réponse de l'assistant, à apprendre après la réponse de l'équipe. */
class KnowledgeQuestion extends Model
{
    protected $fillable = ['organization_id', 'store_id', 'contact_id', 'question', 'status', 'answer', 'answered_by', 'answered_at', 'knowledge_entry_id'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }
}
