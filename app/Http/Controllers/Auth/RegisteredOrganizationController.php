<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ModuleController;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Modules\CarteDigitale\Services\DigitalCards;
use App\Support\SectorPresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inscription d'une entreprise cliente : crée en une fois l'organisation,
 * ses rôles par défaut, son premier établissement (marque + boutique du même
 * nom) et le compte de la personne qui s'inscrit, Super administrateur.
 * L'inscription ne demande que le secteur (4 cartes) : le préréglage du
 * secteur active modules, étapes de suivi et réponses automatiques
 * (App\Support\SectorPresets). L'ancien formulaire (réponses à « Comment
 * travaillez-vous ? », champ `answers`) reste accepté pour les intégrations
 * existantes. La connexion WhatsApp se fait ensuite dans Paramètres.
 */
class RegisteredOrganizationController extends Controller
{
    public function create(Request $request): Response
    {
        // Venue du créateur de carte du site vitrine (?offre=carte&nom=…) : la carte
        // sera créée et préremplie juste après l'inscription.
        $cardDraft = null;
        if ($request->query('offre') === 'carte') {
            $cardDraft = collect($request->only(['nom', 'poste', 'entreprise', 'whatsapp', 'email', 'couleur', 'facebook', 'instagram', 'tiktok', 'linkedin', 'youtube', 'x']))
                ->map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 120) : null)->filter()->all();
            $request->session()->put('card_draft', $cardDraft);
        }

        return Inertia::render('Auth/Register', [
            'prefill' => $cardDraft === null ? null : [
                'name' => $cardDraft['nom'] ?? '',
                'organization_name' => $cardDraft['entreprise'] ?? '',
                'email' => filter_var($cardDraft['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: '',
                'offer' => 'carte',
            ],
            // Inscription publique : secteurs de prestations de services (le commerce n'est plus proposé).
            'sectors' => collect(Organization::PUBLIC_SECTORS)->map(fn ($key) => [
                'key' => $key,
                'label' => Organization::SECTORS[$key]['label'],
                'description' => Organization::SECTORS[$key]['description'],
                'modules' => SectorPresets::PRESETS[$key]['modules'] ?? Organization::SECTORS[$key]['modules'],
                'module_labels' => collect(SectorPresets::PRESETS[$key]['modules'] ?? [])->map(fn ($m) => Organization::MODULES[$m]['label'])->values(),
            ])->values(),
            'families' => collect(Organization::FAMILIES)->map(fn ($f, $key) => [
                'key' => $key,
                'label' => $f['label'],
                'sector' => $f['sector'],
                'metiers' => collect($f['metiers'])->map(fn ($sector, $label) => ['label' => $label, 'sector' => $sector])->values(),
            ])->values(),
            // « Comment travaillez-vous ? » : une réponse oui coche le module.
            'questions' => collect(Organization::WORK_QUESTIONS)->map(fn ($question, $module) => ['module' => $module, 'question' => $question])->values(),
            'moduleDefaults' => Organization::MODULE_DEFAULTS,
            'beneficiaryLabels' => collect(Organization::BENEFICIARY_LABELS)->map(fn ($labels, $key) => ['value' => $key, 'label' => $labels[0]])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // « boutique » : nom public du secteur commerce.
        if (isset(Organization::SECTOR_ALIASES[$request->input('sector')])) {
            $request->merge(['sector' => Organization::SECTOR_ALIASES[$request->input('sector')]]);
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'sector' => ['required', Rule::in(array_keys(Organization::SECTORS))],
            'store_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['boolean'],
            'ai_agents' => ['nullable', 'boolean'],
            'activity' => ['nullable', 'string', 'max:120'],
            ...ModuleController::settingsRules(),
        ]);

        // Modules du secteur, ajustés par les réponses à « Comment travaillez-vous ? ».
        $answers = array_intersect_key($validated['answers'] ?? [], Organization::WORK_QUESTIONS);
        $modules = collect(Organization::defaultModulesFor($validated['sector']))
            ->reject(fn ($module) => array_key_exists($module, $answers))
            ->merge(array_keys(array_filter($answers)))
            ->unique()->values()->all();

        // Slug choisi hors transaction : la transaction commence ainsi par une
        // écriture et attend le verrou SQLite au lieu d'échouer (cf. uniqueSlug).
        $slug = Organization::uniqueSlug($validated['organization_name']);

        $usePreset = ! $request->has('answers') && SectorPresets::has($validated['sector']);

        $user = DB::transaction(function () use ($validated, $slug, $modules, $answers, $usePreset) {
            $organization = Organization::create([
                'name' => $validated['organization_name'],
                'slug' => $slug,
                'sector' => $validated['sector'],
                'activity' => $validated['activity'] ?? null,
                // Analyse seule : imports de ventes réguliers depuis le logiciel de caisse.
                'usage_mode' => $validated['sector'] === 'commerce' ? 'analyse' : 'complete',
                // Sans compte Meta vérifié au départ : relances manuelles par lien wa.me,
                // l'API WhatsApp s'active plus tard dans Organisation.
                'whatsapp_api_enabled' => false,
                // Essai gratuit, suivi dans le back-office plateforme.
                'status' => Organization::STATUS_TRIAL,
                'trial_ends_at' => now()->addDays(Organization::TRIAL_DAYS),
            ]);
            $organization->setModules($modules, $validated['settings'] ?? [], reviewed: $answers !== []);
            $organization->save();

            if ($usePreset) {
                SectorPresets::apply($organization);
            }

            // Case « Agents IA » cochée à l'inscription.
            if (! empty($validated['ai_agents'])) {
                $organization->setModules([...$organization->enabledModules(), 'agents_ia']);
                $organization->save();
            }

            Role::seedDefaultsFor($organization);
            if ($organization->hasModule('prospects')) {
                Role::ensureSalesRole($organization);
            }

            $brand = Brand::create([
                'organization_id' => $organization->id,
                'name' => $validated['store_name'],
            ]);

            Store::create([
                'organization_id' => $organization->id,
                'brand_id' => $brand->id,
                'name' => $validated['store_name'],
            ]);

            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'organization_id' => $organization->id,
                'role_id' => $organization->roles()->where('name', 'Super administrateur')->value('id'),
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        // Carte digitale commencée sur le site vitrine : créée, puis ouverte pour finir.
        if (is_array($draft = $request->session()->pull('card_draft'))) {
            $card = DigitalCards::createFromDraft($user->organization, $user->id, $draft);

            return redirect()->route('cards.edit', $card)
                ->with('success', 'Bienvenue ! Votre carte digitale est prête : ajoutez votre photo puis partagez-la.');
        }

        return redirect()->route('dashboard.index')
            ->with('success', 'Bienvenue ! Connectez maintenant le numéro WhatsApp de votre établissement.');
    }
}
