
import { NavSection } from './navigation';

/*
 * Applications ClientlyX : chaque rubrique du menu est une application avec sa
 * couleur, sa phrase et son propre espace. Signature visuelle : l'hexagone du
 * logo (rail de gauche, « Ma journée » en nid d'abeilles).
 */

export interface AppStyle {
    /** Dégradé de la tuile (classes Tailwind). */
    tile: string;
    /** Phrase courte, centrée sur le client. */
    tagline: string;
}

export const APP_STYLES: Record<string, AppStyle> = {
    home: { tile: 'from-sky-500 to-blue-700', tagline: 'Vos chiffres et vos clients du jour' },
    actions: { tile: 'from-amber-400 to-orange-600', tagline: 'Les clients à relancer aujourd’hui' },
    discussions: { tile: 'from-emerald-400 to-green-600', tagline: 'WhatsApp, réponses auto et IA' },
    agenda: { tile: 'from-violet-500 to-purple-700', tagline: 'Rendez-vous, cours et rappels' },
    rental: { tile: 'from-sky-500 to-cyan-700', tagline: 'Demandes, réservations, cautions et ventes' },
    fleet: { tile: 'from-slate-500 to-slate-700', tagline: 'Véhicules, contrats et entretien' },
    customers: { tile: 'from-blue-500 to-indigo-700', tagline: 'Fiches, fidélité et prospects' },
    sales: { tile: 'from-cyan-500 to-teal-700', tagline: 'Ventes, formules et catalogue' },
    invoices: { tile: 'from-indigo-500 to-blue-800', tagline: 'Devis acceptés sur WhatsApp, factures' },
    cash: { tile: 'from-teal-400 to-emerald-700', tagline: 'Encaissements et clôture du soir' },
    stock: { tile: 'from-orange-400 to-amber-600', tagline: 'Produits vendus avec vos prestations' },
    cards: { tile: 'from-[#E8590C] to-[#2B1A3D]', tagline: 'Cartes de visite avec QR : chaque contact devient un client' },
    funnels: { tile: 'from-orange-500 to-rose-600', tagline: 'Pages d’offre, relances et ventes par campagne' },
    expenses: { tile: 'from-rose-400 to-red-600', tagline: 'Dépenses du mois par catégorie' },
    imports: { tile: 'from-zinc-500 to-zinc-700', tagline: 'Fichiers de ventes et de clients' },
    analytics: { tile: 'from-fuchsia-500 to-pink-700', tagline: 'Pilotage, rapports et segments' },
    campaigns: { tile: 'from-green-500 to-emerald-700', tagline: 'Messages ciblés à vos clients' },
    settings: { tile: 'from-gray-500 to-gray-700', tagline: 'Entreprise, modules et équipe' },
    platform: { tile: 'from-blue-900 to-slate-900', tagline: 'Back-office ClientlyX' },
};

export const appStyle = (key: string): AppStyle => APP_STYLES[key] ?? { tile: 'from-blue-500 to-blue-700', tagline: '' };

/** Icône d'application : hexagone en dégradé (forme du logo ClientlyX), icône blanche. */
export function AppIcon({ section, size = 'md', active = false }: { section: NavSection; size?: 'sm' | 'md' | 'lg' | 'xl'; active?: boolean }) {
    const box =
        size === 'xl'
            ? 'h-[88px] w-[78px] [&_svg]:size-8'
            : size === 'lg'
              ? 'h-16 w-14 [&_svg]:size-6'
              : size === 'sm'
                ? 'h-8 w-7 [&_svg]:size-4'
                : 'h-11 w-10 [&_svg]:size-5';

    return (
        <span className={`nx-hex-wrap ${active ? 'is-active' : ''}`} aria-hidden="true">
            <span className={`nx-hex inline-flex shrink-0 items-center justify-center bg-gradient-to-br text-white ${appStyle(section.key).tile} ${box}`}>{section.icon}</span>
        </span>
    );
}
