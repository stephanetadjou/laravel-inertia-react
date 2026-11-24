<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Closure;
use App\Models\KnowledgeEntry;
use App\Models\Organization;
use App\Models\StaffMember;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Base de connaissances d'une entreprise (Assistant IA ClientlyX) :
 * recherche des fiches les plus proches d'une question, par les mots
 * (toujours) et par le sens si un service d'embeddings est configuré
 * (config clientlyx_ai.embeddings). Les prestations du catalogue et les
 * horaires des établissements s'ajoutent d'office, toujours à jour.
 */
class KnowledgeBase
{
    /** Au-dessus de ce score, la fiche répond seule à la question (sans IA). */
    public const DIRECT_SCORE = 0.5;

    private const STOPWORDS = ['a', 'au', 'aux', 'avec', 'ce', 'ces', 'c', 'est', 'cest', 'dans', 'de', 'des', 'du', 'elle', 'en', 'et', 'eux', 'il', 'ils', 'je', 'j', 'la', 'le', 'les', 'leur', 'lui', 'ma', 'mais', 'me', 'meme', 'mes', 'moi', 'mon', 'ne', 'nos', 'notre', 'nous', 'on', 'ou', 'par', 'pas', 'pour', 'qu', 'que', 'qui', 'sa', 'se', 'ses', 'son', 'sur', 'ta', 'te', 'tes', 'toi', 'ton', 'tu', 'un', 'une', 'vos', 'votre', 'vous', 'y', 'l', 'd', 'n', 's', 't', 'm', 'est', 'sont', 'suis', 'etes', 'ai', 'as', 'avez', 'avons', 'ont', 'fait', 'faire', 'faites', 'bonjour', 'bonsoir', 'salut', 'svp', 'merci', 'possible', 'peut', 'peux', 'pouvez', 'voudrais', 'veux', 'voulez', 'aimerais', 'quel', 'quelle', 'quels', 'quelles', 'comment', 'est-ce', 'estce', 'si', 'oui', 'non', 'bien', 'tres', 'plus', 'chez'];

    /** Mots de même sens ramenés à un seul (après le retrait du pluriel). */
    private const SYNONYMS = [
        'payer' => 'paiement', 'paye' => 'paiement', 'payez' => 'paiement', 'regler' => 'paiement', 'reglement' => 'paiement',
        'tarif' => 'prix', 'cout' => 'prix', 'combien' => 'prix', 'coute' => 'prix',
        'rdv' => 'rendez', 'reserver' => 'reservation', 'reserve' => 'reservation',
        'adresse' => 'situe', 'trouve' => 'situe', 'trouvez' => 'situe', 'localisation' => 'situe', 'venir' => 'situe',
        'annuler' => 'annulation', 'annule' => 'annulation',
        'livrer' => 'livraison', 'livre' => 'livraison',
        'ouvert' => 'horaire', 'ouverture' => 'horaire', 'ferme' => 'horaire', 'fermeture' => 'horaire',
    ];

    public function __construct(private Organization $organization) {}

    /**
     * Fiches actives (sauf « à ne pas dire ») et fiches de l'activité.
     *
     * @return Collection<int, KnowledgeEntry>
     */
    public function entries(?Store $store = null): Collection
    {
        $saved = KnowledgeEntry::where('organization_id', $this->organization->id)
            ->where('active', true)
            ->whereNotIn('section', ['interdit', 'consignes'])
            ->get();

        return $saved->concat($this->businessEntries($store));
    }

    /** Consignes « à ne pas dire / passer à un humain ». @return list<string> */
    public function forbidden(): array
    {
        return KnowledgeEntry::where('organization_id', $this->organization->id)->where('active', true)->where('section', 'interdit')
            ->get()->map(fn ($e) => trim($e->title.' : '.$e->content, ' :'))->all();
    }

    /** Consignes internes de conduite de la conversation (jamais envoyées). @return list<string> */
    public function instructions(): array
    {
        return KnowledgeEntry::where('organization_id', $this->organization->id)->where('active', true)->where('section', 'consignes')
            ->get()->map(fn ($e) => trim($e->title.' : '.$e->content, ' :'))->all();
    }

    /**
     * Fiches les plus proches de la question, du meilleur score au moins bon.
     *
     * @return list<array{entry: KnowledgeEntry, score: float}>
     */
    public function search(string $question, ?Store $store = null, int $limit = 5): array
    {
        $tokens = self::tokens($question);
        $queryVector = $this->embeddingsEnabled() ? ($this->embed([$question], 'query')[0] ?? null) : null;

        $entries = $this->entries($store);

        // Poids des mots : un mot présent dans peu de fiches compte plus (« équipe » plus que « prix »).
        $weights = [];
        $absent = [];
        foreach ($tokens as $q) {
            $found = $entries->filter(fn (KnowledgeEntry $e) => self::contains(self::tokens($e->text()), $q))->count();
            if ($found === 0) {
                $absent[] = $q;
            } else {
                $weights[$q] = log(1 + ($entries->count() + 1) / ($found + 1));
            }
        }
        // Un mot absent de toute la base (« exactement », une faute de frappe) compte peu, mais compte.
        foreach ($absent as $q) {
            $weights[$q] = log(2);
        }

        return $entries
            ->map(function (KnowledgeEntry $entry) use ($tokens, $queryVector, $weights) {
                $score = self::lexicalScore($tokens, $entry, $weights);
                if ($queryVector !== null && is_array($entry->embedding) && $entry->embedding_model === $this->embeddingModel()) {
                    $score = 0.5 * $score + 0.5 * max(0.0, self::cosine($queryVector, $entry->embedding));
                }

                return ['entry' => $entry, 'score' => round($score, 4)];
            })
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /** Calcule le vecteur d'une fiche (si un service d'embeddings est configuré). */
    public function index(KnowledgeEntry $entry): void
    {
        if (! $this->embeddingsEnabled()) {
            return;
        }

        $vector = $this->embed([$entry->text()], 'document')[0] ?? null;
        if ($vector !== null) {
            $entry->forceFill(['embedding' => $vector, 'embedding_model' => $this->embeddingModel()])->saveQuietly();
        }
    }

    public function embeddingsEnabled(): bool
    {
        return in_array(config('clientlyx_ai.embeddings.driver'), ['voyage', 'openai'], true) && filled(config('clientlyx_ai.embeddings.key'));
    }

    /**
     * Lit un document structuré (Excel ou CSV : Rubrique, Titre ou question, Contenu ou réponse).
     *
     * @return array{rows: list<array{section: string, title: string, content: string}>, errors: list<string>}
     */
    public static function readDocument(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $rows = [];
        $errors = [];

        foreach (array_slice($sheet, 1) as $i => $line) {
            [$section, $title, $content] = array_map(fn ($v) => trim((string) $v), array_pad(array_slice($line, 0, 3), 3, ''));
            if ($section === '' && $title === '' && $content === '') {
                continue;
            }
            $key = self::sectionOf($section);
            if ($key === null) {
                $errors[] = 'Ligne '.($i + 2).' : rubrique « '.$section.' » inconnue.';

                continue;
            }
            if ($title === '' || $content === '') {
                $errors[] = 'Ligne '.($i + 2).' : titre et contenu obligatoires.';

                continue;
            }
            $rows[] = ['section' => $key, 'title' => mb_substr($title, 0, 255), 'content' => mb_substr($content, 0, 5000)];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    public static function sectionOf(string $label): ?string
    {
        $t = Str::of($label)->ascii()->lower()->value();

        return match (true) {
            $t === '' => null,
            str_contains($t, 'consigne') || str_contains($t, 'interne') => 'consignes',
            str_contains($t, 'interdit') || str_contains($t, 'ne pas') || str_contains($t, 'humain') => 'interdit',
            str_contains($t, 'presentation') || str_contains($t, 'activite') => 'presentation',
            str_contains($t, 'prestation') || str_contains($t, 'tarif') || str_contains($t, 'prix') => 'prestations',
            str_contains($t, 'horaire') || str_contains($t, 'equipe') => 'horaires',
            str_contains($t, 'regle') || str_contains($t, 'annulation') || str_contains($t, 'paiement') => 'regles',
            str_contains($t, 'faq') || str_contains($t, 'question') => 'faq',
            default => null,
        };
    }

    /** @return list<string> mots significatifs, sans accents, au singulier approximatif */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::of($text)->ascii()->lower()->value(), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map(
            function ($w) {
                $singular = strlen($w) > 3 ? preg_replace('/(s|x)$/', '', $w) : $w;

                return self::stem(self::SYNONYMS[$w] ?? self::SYNONYMS[$singular] ?? $singular);
            },
            array_filter($words, fn ($w) => ! in_array($w, self::STOPWORDS, true) && strlen($w) > 1),
        ))));
    }

    /** Racine approximative (« facturation » et « facture » → « factur »), 4 lettres au moins. */
    private static function stem(string $word): string
    {
        foreach (['ations', 'ation', 'ements', 'ement', 'euses', 'euse', 'eurs', 'eur', 'ees', 'ee', 'es', 'er', 'ez', 'e'] as $suffix) {
            if (str_ends_with($word, $suffix) && strlen($word) - strlen($suffix) >= 4) {
                return substr($word, 0, -strlen($suffix));
            }
        }

        return $word;
    }

    /** Part des mots de la question retrouvés dans la fiche (titre : 1, contenu : 0,7). */
    /** @param  array<string, float>  $weights  poids de chaque mot de la question (1 par défaut) */
    public static function lexicalScore(array $queryTokens, KnowledgeEntry $entry, array $weights = []): float
    {
        if ($queryTokens === []) {
            return 0.0;
        }

        $title = self::tokens($entry->title);
        $keywords = self::tokens((string) $entry->keywords);
        $content = self::tokens($entry->content);
        $sum = $total = 0.0;

        // Titre et autres formulations > contenu.
        foreach ($queryTokens as $q) {
            $weight = $weights[$q] ?? 1.0;
            $total += $weight;
            $sum += $weight * match (true) {
                self::contains($title, $q) => 1.0,
                self::contains($keywords, $q) => 1.0,
                self::contains($content, $q) => 0.6,
                default => 0.0,
            };
        }

        return $total > 0 ? $sum / $total : 0.0;
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $v) {
            $w = (float) ($b[$i] ?? 0);
            $dot += $v * $w;
            $na += $v * $v;
            $nb += $w * $w;
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /** Mot présent tel quel, ou même racine (« photo » / « photographe », 4 lettres au moins). */
    private static function contains(array $tokens, string $q): bool
    {
        foreach ($tokens as $t) {
            if ($t === $q || (strlen($q) >= 4 && strlen($t) >= 4 && (str_starts_with($t, $q) || str_starts_with($q, $t)))) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, KnowledgeEntry> prestations du catalogue et horaires (non enregistrées) */
    /**
     * Ce que la configuration de ClientlyX dit déjà (jamais ressaisi) : entreprise,
     * établissements (horaires, pause, fermetures, contact), prestations (prix,
     * durée, intervenants), équipe, réservation, fidélité. Toujours à jour.
     *
     * @return Collection<int, KnowledgeEntry>
     */
    public function businessEntries(?Store $store = null): Collection
    {
        $org = $this->organization;
        $currency = $org->currencySymbol();
        $entries = collect();
        $make = fn (string $section, string $title, string $content, string $keywords = '', string $key = '') => $entries->push(new KnowledgeEntry([
            'section' => $section, 'title' => $title, 'content' => $content, 'keywords' => $keywords ?: null, 'source' => 'business', 'external_id' => $key ?: null,
        ]));

        $sector = Organization::SECTORS[$org->sector]['label'] ?? null;
        $make('presentation', 'Qui sommes-nous', $org->name.($sector ? ' : '.$sector : '').'.', 'qui etes vous presentation activite faites quoi entreprise', 'business:org');

        $bookable = $org->hasAgenda() && $org->bookingSettings()['enabled'];
        $services = CatalogItem::where('organization_id', $org->id)->where('active', true)->with('staffMembers:id,name')->orderBy('type')->orderBy('name')->limit(100)->get();
        $price = fn (CatalogItem $i) => $i->price > 0 ? number_format($i->price, 0, ',', ' ').' '.$currency : 'prix sur demande';

        foreach ($services as $i) {
            $staff = $i->staffMembers->pluck('name')->implode(', ');
            $make('prestations', $i->name, $i->name.' : '.$price($i)
                .($i->type === CatalogItem::TYPE_SERVICE && $i->duration_minutes ? ', durée '.$i->duration_minutes.' min' : '')
                .($staff !== '' ? ', avec '.$staff : '')
                .($bookable && $i->type === CatalogItem::TYPE_SERVICE && $i->bookable_online ? '. Réservable ici sur WhatsApp : écrivez « RDV ».' : '.'),
                'prix tarif combien duree '.$i->name, 'business:item:'.$i->id);
        }
        if ($services->isNotEmpty()) {
            $make('prestations', 'Nos prestations et tarifs', 'Nos prestations : '.$services->take(15)->map(fn ($i) => $i->name.' ('.$price($i).')')->implode(', ').'.',
                'prestations services offres liste tarifs prix catalogue proposez faites', 'business:items');
        }

        $stores = $store ? collect([$store]) : $org->stores()->get();
        foreach ($stores as $s) {
            $break = $s->bookingBreak();
            $closures = Closure::affecting($s, today(), today()->addDays(30))->orderBy('starts_on')->get();
            $make('horaires', 'Horaires d’ouverture '.$s->name, $s->name.($s->city ? ' ('.$s->city.')' : '').' est ouvert : '.AutoReplies::hoursText($s).'.'
                .($break ? ' Pause de '.str_replace(':', 'h', $break['start']).' à '.str_replace(':', 'h', $break['end']).'.' : '')
                .($closures->isNotEmpty() ? ' Fermetures à venir : '.$closures->map(fn ($c) => ($c->label ? $c->label.' ' : '').'du '.$c->starts_on->format('d/m').($c->ends_on && ! $c->ends_on->equalTo($c->starts_on) ? ' au '.$c->ends_on->format('d/m') : ''))->implode(', ').'.' : ''),
                'horaire ouvert ferme heure jour dimanche samedi pause ferie conge', 'business:hours:'.$s->id);

            if ($phone = $s->bookingPhone()) {
                $make('presentation', 'Contact '.$s->name, 'Vous pouvez joindre '.$s->name.' sur WhatsApp au +'.$phone.($s->city ? ', à '.$s->city : '').'.',
                    'contact numero telephone joindre appeler whatsapp', 'business:contact:'.$s->id);
            }
        }

        $team = StaffMember::where('organization_id', $org->id)->where('active', true)->orderBy('name')->pluck('name');
        if ($team->isNotEmpty()) {
            $make('horaires', 'Notre équipe', 'Notre équipe : '.$team->implode(', ').'.', 'equipe qui intervenant praticien coach coiffeuse personnel', 'business:team');
        }

        if ($bookable) {
            $settings = $org->bookingSettings();
            $make('regles', 'Prendre rendez-vous', 'Pour prendre rendez-vous, écrivez « RDV » ici : je vous propose les créneaux libres. Vous pouvez aussi réserver en ligne : '.$org->bookingUrl().'.'
                .($settings['min_notice_minutes'] >= 60 ? ' Réservation au plus tard '.intdiv($settings['min_notice_minutes'], 60).' h à l’avance.' : '')
                .($settings['auto_confirm'] ? ' Le rendez-vous est confirmé tout de suite.' : ' L’équipe confirme chaque demande.'),
                'rendez vous rdv reserver reservation creneau disponible disponibilite place', 'business:booking');
        }

        $loyalty = $org->loyaltySettings();
        if ($loyalty['enabled']) {
            $make('regles', 'Programme de fidélité', 'Programme de fidélité : 1 point pour '.number_format($loyalty['fcfa_per_point'], 0, ',', ' ').' '.$currency.' dépensés'
                .($loyalty['tiers'] !== [] ? ', paliers '.collect($loyalty['tiers'])->pluck('name')->implode(', ') : '').'.', 'fidelite points carte avantage recompense', 'business:loyalty');
        }

        return $entries;
    }

    private function embeddingModel(): string
    {
        return (string) (config('clientlyx_ai.embeddings.model') ?: (config('clientlyx_ai.embeddings.driver') === 'openai' ? 'text-embedding-3-small' : 'voyage-4'));
    }

    /** @param  list<string>  $texts  @return list<list<float>> */
    private function embed(array $texts, string $type): array
    {
        try {
            $driver = config('clientlyx_ai.embeddings.driver');
            $response = $driver === 'openai'
                ? Http::timeout(20)->withToken(config('clientlyx_ai.embeddings.key'))->post('https://api.openai.com/v1/embeddings', ['model' => $this->embeddingModel(), 'input' => $texts])
                : Http::timeout(20)->withToken(config('clientlyx_ai.embeddings.key'))->post('https://api.voyageai.com/v1/embeddings', ['model' => $this->embeddingModel(), 'input' => $texts, 'input_type' => $type]);

            if (! $response->successful()) {
                Log::warning('Base de connaissances : embeddings en échec', ['status' => $response->status()]);

                return [];
            }

            return collect($response->json('data', []))->sortBy('index')->pluck('embedding')->values()->all();
        } catch (Throwable $e) {
            Log::warning('Base de connaissances : embeddings indisponibles', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
