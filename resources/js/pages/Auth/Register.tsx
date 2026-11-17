import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import Button from '../../components/ui/button/Button';
import MenentiaLogo from '../../components/common/MenentiaLogo';

type Field = 'organization_name' | 'sector' | 'store_name' | 'name' | 'email' | 'password' | 'password_confirmation';

interface Sector {
    key: string;
    label: string;
    description: string;
    modules: string[];
    module_labels: string[];
}

interface Family {
    key: string;
    label: string;
    sector: string;
    metiers: { label: string; sector: string }[];
}

interface RegisterProps {
    sectors: Sector[];
    families: Family[];
    /** Venue du créateur de carte du site (?offre=carte) : champs préremplis. */
    prefill?: { name: string; organization_name: string; email: string; offer: string } | null;
    [key: string]: unknown;
}

interface RegisterForm {
    organization_name: string;
    sector: string;
    activity: string;
    store_name: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    ai_agents: boolean;
}

// Ce que chaque secteur gagne avec ClientlyX : affiché quand on ouvre la carte (flèche).
const BENEFITS: Record<string, { resource: string; points: string[] }> = {
    beaute: {
        resource: 'Remplir les créneaux de vos intervenantes',
        points: [
            'Rappel WhatsApp la veille, moins de rendez-vous oubliés',
            'Les clientes qui dépassent leur délai habituel sont relancées, message prêt',
            'Réservation en ligne : vos clientes choisissent elles-mêmes un créneau libre',
        ],
    },
    sante: {
        resource: 'Remplir l’agenda de vos praticiens',
        points: [
            'Rappel WhatsApp la veille avec confirmation : moins de patients absents',
            'Rappel du contrôle (détartrage, suivi) au bon moment, message prêt',
            'Fiche de chaque patient, personnes suivies pour un parent',
        ],
    },
    optique: {
        resource: 'Remplir l’agenda des examens de vue',
        points: [
            'Examen de vue, ordonnance puis devis lunettes accepté sur WhatsApp',
            'Montures et verres en stock : alerte avant la rupture',
            'Rappel du contrôle annuel au bon moment, message prêt',
        ],
    },
    technique: {
        resource: 'Occuper l’atelier et vos mécaniciens',
        points: [
            'Rendez-vous atelier et rappel du prochain entretien (vidange, révision)',
            'Devis relancés à J+3, demandes de devis suivies par étapes',
            'Message « véhicule prêt » et historique de chaque client',
        ],
    },
    sport: {
        resource: 'Garder vos abonnés actifs, chaque semaine',
        points: [
            'Chaque membre suivi : moins de 2 venues par semaine = alerte de décrochage',
            'Abonnements, échéances et relances de paiement suivis',
            'Appel et absences : les membres qui décrochent sont repérés',
        ],
    },
    formation: {
        resource: 'Remplir chaque session et chaque salle',
        points: [
            'Heures, progression et examens de chaque apprenant',
            'Formation payée en plusieurs fois, tranches relancées',
            'Alerte de décrochage avant l’abandon',
        ],
    },
    vehicules: {
        resource: 'Toujours une voiture prête, aucune qui dort au parking',
        points: [
            'Planning de la flotte, contrats courte et longue durée, avec ou sans chauffeur',
            'Alerte quand il reste moins de 2 véhicules libres, ou qu’un véhicule ne sort plus',
            'Entretien, assurance, visite technique et véhicules à vendre suivis',
        ],
    },
    pro: {
        resource: 'Bien répartir les heures de votre équipe',
        points: [
            'Prospects suivis par étapes, relance du devis à J+3',
            'Commerciaux, objectifs et résultats sans Excel',
            'Aucune demande WhatsApp perdue',
        ],
    },
    immobilier: {
        resource: 'Transformer chaque visite en mandat ou en vente',
        points: [
            'Prospects suivis par étape, de la demande à la signature',
            'Visites dans l’agenda, rappel WhatsApp la veille',
            'Devis et relances au bon moment, message prêt',
        ],
    },
    commerce: {
        resource: 'Comprendre vos clients à partir de vos ventes',
        points: [
            'Import régulier des ventes de votre logiciel, chaque vente rattachée au client',
            'Segments RFM, clients à risque et analyse par famille',
            'Relances et campagnes WhatsApp ciblées',
        ],
    },
};

const STEP2_FIELDS: Field[] = ['organization_name', 'store_name', 'name', 'email', 'password', 'password_confirmation'];

const inputClass =
    'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function Arrow({ open }: { open: boolean }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" className={`h-4 w-4 transition-transform ${open ? 'rotate-90' : ''}`} aria-hidden="true">
            <path d="M5 12h14M13 6l6 6-6 6" />
        </svg>
    );
}

export default function Register() {
    const { sectors, families, prefill } = usePage<RegisterProps>().props;
    const { data, setData, post, processing, errors } = useForm<RegisterForm>({
        organization_name: prefill?.organization_name ?? '',
        sector: '',
        activity: '',
        store_name: prefill?.organization_name ?? '',
        name: prefill?.name ?? '',
        email: prefill?.email ?? '',
        password: '',
        password_confirmation: '',
        ai_agents: false,
    });
    const [step, setStep] = useState<1 | 2>(1);
    const [family, setFamily] = useState<Family | null>(null);
    const [localErrors, setLocalErrors] = useState<Partial<Record<Field, string>>>({});

    // Un seul choix : le secteur prépare modules, étapes de suivi et réponses automatiques.
    const sector = sectors.find((s) => s.key === data.sector);
    const shown = sector;
    const pickFamily = (f: Family) => {
        setFamily(f);
        setData((d) => ({ ...d, sector: f.metiers.length ? '' : f.sector, activity: f.metiers.length ? '' : f.label }));
    };

    // Étape 1 → 2 : le secteur est choisi.
    const next: FormEventHandler = (e) => {
        e.preventDefault();
        if (sector) setStep(2);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const missing: Partial<Record<Field, string>> = {};
        STEP2_FIELDS.forEach((f) => {
            if (!data[f].trim()) missing[f] = 'Ce champ est obligatoire.';
        });
        if (data.password && data.password !== data.password_confirmation) {
            missing.password_confirmation = 'Les deux mots de passe ne correspondent pas.';
        }
        setLocalErrors(missing);
        if (Object.keys(missing).length > 0) return;

        post('/register', {
            // Secteur refusé : retour à la première étape.
            onError: (errs) => {
                if ('sector' in errs) setStep(1);
            },
        });
    };

    const field = (id: Field, label: string, type = 'text', hint?: string) => {
        const error = errors[id] ?? localErrors[id];
        return (
            <div>
                <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {label}
                </label>
                <input id={id} type={type} value={data[id]} onChange={(e) => setData(id, e.target.value)} className={inputClass} />
                {hint && !error && <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{hint}</p>}
                {error && <p className="mt-1.5 text-sm text-error-500">{error}</p>}
            </div>
        );
    };

    const header = (title: string, lead: string) => (
        <div className="mb-8 text-center">
            <div className="mb-5 flex justify-center">
                <MenentiaLogo symbolClassName="h-10 w-10" wordmarkClassName="text-[28px]" />
            </div>
            <p className="mb-2 text-xs font-semibold text-brand-text">Étape {step} sur 2</p>
            <h1 className="text-xl font-bold text-ink-strong dark:text-white">{title}</h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{lead}</p>
            {prefill?.offer === 'carte' && (
                <p className="mx-auto mt-4 max-w-md rounded-lg bg-[#FFF4E6] px-4 py-2 text-sm font-medium text-[#B8460A]">Votre carte digitale gratuite sera créée avec les informations saisies, juste après l’inscription.</p>
            )}
        </div>
    );

    return (
        <>
            <Head title="Créer un compte entreprise" />

            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10 dark:bg-gray-900">
                {step === 1 ? (
                    <div className="w-full max-w-6xl rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-md dark:border-gray-800 dark:bg-gray-900">
                        {header('Créer un compte entreprise', 'Choisissez votre activité puis votre métier : ClientlyX se règle tout seul.')}

                        <form onSubmit={next} className="space-y-6">
                            {[
                                { title: 'Beauté, bien-être, santé et sport', keys: ['beaute', 'sante', 'sport'], big: true },
                                { title: 'Autres établissements de services', keys: families.map((f) => f.key).filter((k) => !['beaute', 'sante', 'sport'].includes(k)), big: false },
                            ].map((group) => (
                                <div key={group.title}>
                                    <p className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">{group.title}</p>
                                    <div role="radiogroup" aria-label={group.title} className={`grid gap-2.5 ${group.big ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-7'}`}>
                                        {families
                                            .filter((f) => group.keys.includes(f.key))
                                            .map((f) => {
                                                const on = family?.key === f.key;
                                                return (
                                                    <button
                                                        key={f.key}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={on}
                                                        onClick={() => pickFamily(f)}
                                                        className={`rounded-xl border text-left font-semibold transition-colors ${group.big ? 'px-4 py-4 text-[15px]' : 'px-3 py-2.5 text-[13px]'} ${
                                                            on ? 'border-brand-500 bg-brand-50 text-ink-strong ring-1 ring-brand-500 dark:bg-white/10 dark:text-white' : 'border-gray-200 text-gray-800 hover:border-gray-300 dark:border-gray-700 dark:text-white/90'
                                                        } ${f.metiers.length === 0 ? 'border-dashed' : ''}`}
                                                    >
                                                        {f.label}
                                                    </button>
                                                );
                                            })}
                                    </div>
                                </div>
                            ))}

                            {family && family.metiers.length > 0 && (
                                <div>
                                    <p className="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Votre métier</p>
                                    <div role="radiogroup" aria-label="Métier" className="flex flex-wrap gap-2">
                                        {family.metiers.map((m) => {
                                            const on = data.activity === m.label;
                                            return (
                                                <button
                                                    key={m.label}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={on}
                                                    onClick={() => setData((d) => ({ ...d, sector: m.sector, activity: m.label }))}
                                                    className={`rounded-full border px-3.5 py-1.5 text-sm ${on ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 text-gray-700 hover:border-brand-300 dark:border-gray-700 dark:text-gray-300'}`}
                                                >
                                                    {m.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {shown && BENEFITS[shown.key] && (
                                <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-5 dark:border-white/10 dark:bg-white/5">
                                    <p className="text-xs font-semibold text-brand-text">{shown.label} : ce que ClientlyX vous apporte</p>
                                    <p className="mt-1 text-base font-bold text-ink-strong dark:text-white">{BENEFITS[shown.key].resource}</p>
                                    <ul className="mt-3 grid gap-2 text-sm text-gray-700 sm:grid-cols-3 dark:text-gray-300">
                                        {BENEFITS[shown.key].points.map((p) => (
                                            <li key={p} className="flex gap-2">
                                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" />
                                                {p}
                                            </li>
                                        ))}
                                    </ul>
                                    {shown.module_labels.length > 0 && (
                                        <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                            Prêt à l’emploi : {shown.module_labels.join(', ')}. Tout reste modifiable dans Paramètres › Modules.
                                        </p>
                                    )}
                                </div>
                            )}

                            {errors.sector && <p className="text-sm text-error-500">Choisissez votre activité et votre métier.</p>}

                            <div className="flex flex-col gap-4 border-t border-gray-100 pt-5 md:flex-row md:items-center md:justify-between dark:border-gray-800">
                                <label className="flex cursor-pointer items-start gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={data.ai_agents}
                                        onChange={(e) => setData('ai_agents', e.target.checked)}
                                        className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-500"
                                    />
                                    <span>
                                        <span className="block font-semibold text-gray-800 dark:text-white/90">Activer les Agents IA</span>
                                        <span className="block text-xs leading-snug text-gray-500 dark:text-gray-400">
                                            Un assistant répond à vos clients sur WhatsApp, 24 h/24, et passe la main à votre équipe si besoin.
                                        </span>
                                    </span>
                                </label>
                                <div className="flex shrink-0 items-center gap-4">
                                    <span className="text-sm text-gray-500 dark:text-gray-400">
                                        Déjà un compte ?{' '}
                                        <Link href="/login" className="font-medium text-brand-text hover:underline">
                                            Se connecter
                                        </Link>
                                    </span>
                                    <Button disabled={!sector}>Continuer</Button>
                                </div>
                            </div>
                        </form>
                    </div>
                ) : (
                    <div className="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-md dark:border-gray-800 dark:bg-gray-900">
                        {header('Votre entreprise et votre accès', `Activité : ${data.activity || sector?.label || ''}. Il reste à créer votre compte.`)}

                        <form onSubmit={submit} className="space-y-5" noValidate>
                            <fieldset className="space-y-4">
                                <legend className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Entreprise</legend>
                                {field('organization_name', "Nom de l'entreprise")}
                                {field('store_name', 'Premier établissement', 'text', 'Vous pourrez en ajouter d’autres plus tard.')}
                            </fieldset>

                            <fieldset className="space-y-4">
                                <legend className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Votre compte</legend>
                                {field('name', 'Nom complet')}
                                {field('email', 'Email', 'email')}
                                {field('password', 'Mot de passe', 'password')}
                                {field('password_confirmation', 'Confirmer le mot de passe', 'password')}
                            </fieldset>

                            <div className="flex gap-3">
                                <button type="button" onClick={() => setStep(1)} className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300">
                                    Retour
                                </button>
                                <Button className="flex-1 justify-center" disabled={processing}>
                                    {processing ? 'Création...' : 'Créer mon compte'}
                                </Button>
                            </div>
                        </form>
                    </div>
                )}
            </div>
        </>
    );
}
