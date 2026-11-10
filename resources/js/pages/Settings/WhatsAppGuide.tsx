import { useEffect, useRef, useState } from 'react';

export interface SupportHelp {
    whatsapp: string | null;
    email: string | null;
    organization: string | null;
}

interface Step {
    key: string;
    title: string;
    link?: { label: string; url: string };
    items: React.ReactNode[];
}

const STEPS: Step[] = [
    {
        key: 'portfolio',
        title: 'Créer le portefeuille Meta de votre entreprise',
        link: { label: 'Ouvrir Meta Business', url: 'https://business.facebook.com/' },
        items: [
            'Connectez-vous avec votre compte Facebook personnel (le vôtre ou celui du gérant).',
            'Créez un portefeuille d’entreprise au nom exact de votre activité. Le site web est facultatif.',
        ],
    },
    {
        key: 'app',
        title: 'Créer l’application Meta et ajouter WhatsApp',
        link: { label: 'Ouvrir Meta for Developers', url: 'https://developers.facebook.com/apps' },
        items: [
            <>« Créer une app », type <b>Entreprise</b>, rattachée au portefeuille de l’étape 1.</>,
            <>Dans l’app, ajoutez le produit <b>WhatsApp</b> : Meta crée votre compte WhatsApp Business.</>,
        ],
    },
    {
        key: 'number',
        title: 'Ajouter votre numéro et noter les identifiants',
        link: { label: 'Ouvrir le Gestionnaire WhatsApp', url: 'https://business.facebook.com/wa/manage/home/' },
        items: [
            <>App › WhatsApp › <b>Configuration de l’API</b> › « Ajouter un numéro de téléphone ».</>,
            'Saisissez le nom d’affichage (le nom que vos clients connaissent) puis le code reçu par SMS. Si le SMS n’arrive pas, choisissez l’appel vocal.',
            <>Sur cette même page, notez le <b>Phone Number ID</b> et le <b>WABA ID</b> (identifiant du compte WhatsApp Business). L’<b>App ID</b> est en haut du tableau de bord de l’app.</>,
        ],
    },
    {
        key: 'token',
        title: 'Créer le token d’accès permanent',
        link: { label: 'Ouvrir les utilisateurs système', url: 'https://business.facebook.com/settings/system-users' },
        items: [
            <>Paramètres de l’entreprise › Utilisateurs › <b>Utilisateurs système</b> › Ajouter (rôle <b>Admin</b>).</>,
            <>« Attribuer des éléments » : votre app <b>et</b> votre compte WhatsApp, en contrôle total.</>,
            <>« Générer un token » : choisissez l’app, expiration <b>Jamais</b>, cochez <code>whatsapp_business_messaging</code> et <code>whatsapp_business_management</code>.</>,
            'Copiez le token tout de suite : Meta ne le réaffiche pas. Ne l’envoyez jamais par message ou e-mail, collez-le seulement ici.',
        ],
    },
    {
        key: 'secret',
        title: 'Récupérer l’App Secret',
        link: { label: 'Ouvrir mes apps', url: 'https://developers.facebook.com/apps' },
        items: [<>App › Paramètres de l’app › <b>Général</b> › Clé secrète › « Afficher ».</>],
    },
    {
        key: 'nentix',
        title: 'Tout saisir dans NENTIX et tester',
        items: [
            'Remplissez le formulaire de votre établissement ci-dessous, puis « Enregistrer » et « Tester ».',
            <>Recopiez l’URL de rappel et le jeton de vérification dans l’app › WhatsApp › <b>Configuration</b> › Webhook, puis abonnez le champ « messages ».</>,
            '« Enregistrer le numéro » avec un code PIN de 6 chiffres de votre choix, puis envoyez-vous un message de test.',
            'Pour les campagnes, ajoutez un moyen de paiement dans le Gestionnaire WhatsApp : Meta facture les messages marketing.',
        ],
    },
];

const PREPARE = [
    'Un compte Facebook personnel actif, avec son mot de passe.',
    'Le numéro qui servira pour WhatsApp, capable de recevoir un SMS ou un appel de l’étranger, et son téléphone à portée de main.',
    'Le nom exact de votre activité, tel que vos clients le connaissent.',
    'Votre logo ou une photo carrée, et une adresse e-mail que vous consultez.',
    'Environ 45 minutes, de préférence sur un ordinateur.',
];

const PROBLEMS: [string, string][] = [
    ['Le code SMS de Meta n’arrive pas', 'Choisissez l’appel vocal, attendez quelques heures entre deux essais, ou utilisez un numéro qui reçoit l’international.'],
    ['Le numéro est déjà sur WhatsApp', 'Sauvegardez puis supprimez le compte WhatsApp de ce numéro sur le téléphone, ou contactez-nous avant d’aller plus loin.'],
    ['Nom d’affichage refusé', 'Utilisez le nom de votre page Facebook, sans mot générique seul (« Salon », « Boutique »).'],
    ['« Tester » échoue', 'Vérifiez le Phone Number ID, que le token a bien les 2 permissions et que le compte WhatsApp est attribué à l’utilisateur système.'],
    ['Le token ne marche plus le lendemain', 'C’était le token temporaire de 24 h : refaites l’étape 4 avec l’expiration « Jamais ».'],
];

const STORAGE_KEY = 'nentix.whatsappGuide';

function readDone(): Record<string, boolean> {
    try {
        return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}') ?? {};
    } catch {
        return {};
    }
}

export function helpUrl(help: SupportHelp, step?: string): string | null {
    const text =
        `Bonjour NENTIX, j'ai besoin d'aide pour connecter WhatsApp` +
        (help.organization ? ` (compte ${help.organization})` : '') +
        (step ? `. Je bloque à l'étape : ${step}` : '') +
        '.';
    if (help.whatsapp) return `https://wa.me/${help.whatsapp}?text=${encodeURIComponent(text)}`;
    if (help.email) return `mailto:${help.email}?subject=${encodeURIComponent('Connexion WhatsApp')}&body=${encodeURIComponent(text)}`;
    return null;
}

export function HelpButton({ help, step, className = 'mdash-btn sm' }: { help: SupportHelp; step?: string; className?: string }) {
    const url = helpUrl(help, step);
    if (!url) return null;
    return (
        <a href={url} target="_blank" rel="noopener noreferrer" className={className}>
            Me faire aider par NENTIX
        </a>
    );
}

export default function WhatsAppGuide({ help, defaultOpen }: { help: SupportHelp; defaultOpen: boolean }) {
    const fromLink = typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('guide');
    const [open, setOpen] = useState(defaultOpen || fromLink);
    const [done, setDone] = useState<Record<string, boolean>>({});
    const ref = useRef<HTMLElement>(null);

    useEffect(() => {
        setDone(readDone());
        if (fromLink && ref.current) window.scrollTo({ top: ref.current.getBoundingClientRect().top + window.scrollY - 100 });
    }, [fromLink]);

    function toggle(key: string) {
        const next = { ...done, [key]: !done[key] };
        setDone(next);
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch {
            /* stockage indisponible : la case reste cochée pour cette visite */
        }
    }

    const doneCount = STEPS.filter((s) => done[s.key]).length;
    const current = STEPS.findIndex((s) => !done[s.key]);
    const currentLabel = current >= 0 ? `${current + 1}. ${STEPS[current].title}` : undefined;

    return (
        <section ref={ref} id="guide" className="mdash-panel mb-6" aria-label="Guide de connexion WhatsApp">
            <div className="mdash-ph">
                <h2>
                    Guide : connecter votre WhatsApp pas à pas
                    <span className="mdash-sub"> · {doneCount}/{STEPS.length} étapes faites</span>
                </h2>
                <span className="mdash-stat flex flex-wrap gap-2">
                    <HelpButton help={help} step={currentLabel} className="mdash-btn ghost sm" />
                    <button type="button" className="mdash-btn ghost sm" aria-expanded={open} onClick={() => setOpen(!open)}>
                        {open ? 'Masquer' : 'Afficher le guide'}
                    </button>
                </span>
            </div>

            {open && (
                <div className="space-y-6 p-5 text-sm text-gray-700 dark:text-gray-300">
                    <p>
                        Vous pouvez le faire seul en suivant ces étapes. Si vous bloquez, l’équipe NENTIX vous accompagne par WhatsApp ou lors d’une
                        séance à distance.
                    </p>

                    <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <h3 className="mb-2 font-semibold text-gray-800 dark:text-white/90">Avant de commencer, préparez :</h3>
                        <ul className="list-disc space-y-1 pl-5">
                            {PREPARE.map((p) => (
                                <li key={p}>{p}</li>
                            ))}
                        </ul>
                    </div>

                    <ol className="space-y-3">
                        {STEPS.map((step, i) => (
                            <li
                                key={step.key}
                                className={`rounded-xl border p-4 ${i === current ? 'border-brand-300' : 'border-gray-200 dark:border-gray-800'}`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <label className="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" className="mt-0.5 h-4 w-4" checked={!!done[step.key]} onChange={() => toggle(step.key)} />
                                        <span className={`font-semibold text-gray-800 dark:text-white/90 ${done[step.key] ? 'line-through opacity-60' : ''}`}>
                                            {i + 1}. {step.title}
                                        </span>
                                    </label>
                                    {step.link && (
                                        <a href={step.link.url} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost sm">
                                            {step.link.label} ↗
                                        </a>
                                    )}
                                </div>
                                {!done[step.key] && (
                                    <ul className="mt-2 list-disc space-y-1 pl-12">
                                        {step.items.map((item, j) => (
                                            <li key={j}>{item}</li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ol>

                    <div>
                        <h3 className="mb-2 font-semibold text-gray-800 dark:text-white/90">Problèmes fréquents</h3>
                        <dl className="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                            {PROBLEMS.map(([q, a]) => (
                                <div key={q} className="grid gap-1 p-3 sm:grid-cols-[240px_1fr]">
                                    <dt className="font-medium text-gray-800 dark:text-white/90">{q}</dt>
                                    <dd>{a}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                        <span className="flex-1">Besoin d’un coup de main ? Écrivez-nous, nous vous aidons à finir la connexion.</span>
                        <HelpButton help={help} step={currentLabel} />
                    </div>
                </div>
            )}
        </section>
    );
}
