<?php

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Lance l'envoi d'une campagne : découpe les messages en attente en lots
 * (services.meta_whatsapp.batch_size) envoyés chacun par un SendCampaignBatchJob
 * avec ses propres tentatives. Quand tous les lots sont finis, la campagne
 * passe « Terminée » (ou « Échec » si aucun message n'est parti).
 *
 * Chaque message part toujours via le numéro WhatsApp de la boutique du
 * contact destinataire (cf. Store::whatsappClient()).
 */
class SendCampaignJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Campaign $campaign) {}

    public function handle(): void
    {
        $this->campaign->update(['status' => 'sending']);

        $batchSize = max(1, (int) config('services.meta_whatsapp.batch_size', 50));
        $jobs = $this->campaign->campaignMessages()
            ->where('status', 'pending')
            ->orderBy('id')
            ->pluck('id')
            ->chunk($batchSize)
            ->map(fn ($ids) => new SendCampaignBatchJob($this->campaign->id, $ids->values()->all()))
            ->values()
            ->all();

        if ($jobs === []) {
            $this->campaign->finishSending();

            return;
        }

        $campaignId = $this->campaign->id;

        Bus::batch($jobs)
            ->name("Campagne #{$campaignId}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($campaignId) {
                Campaign::find($campaignId)?->finishSending();
            })
            ->dispatch();
    }
}
