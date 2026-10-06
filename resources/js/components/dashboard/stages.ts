/**
 * Étapes du parcours client du Groupe (cf. App\Models\Customer::STAGES) :
 * libellé, pastille (classes .mdash-pill de dashboard.css) et définition.
 *
 * Le statut VIP n'est pas une étape : il est propre à chaque boutique
 * (contact.is_vip), cf. VIP_PILL.
 */
export type Stage = 'contact' | 'prospect' | 'new' | 'active' | 'at_risk' | 'churned';

export const STAGES: Record<Stage, { label: string; pill: string; hint: string }> = {
    contact: { label: 'Contact', pill: 'idle', hint: 'Numéro connu, aucun achat ni opt-in' },
    prospect: { label: 'Prospect', pill: 'info', hint: 'Opt-in WhatsApp, pas encore acheteur' },
    new: { label: 'Nouveau client', pill: 'ok', hint: '1er achat récent' },
    active: { label: 'Client actif', pill: 'ok', hint: 'Achète régulièrement' },
    at_risk: { label: 'À risque', pill: 'warn', hint: 'Pas d’achat depuis un moment' },
    churned: { label: 'Inactif', pill: 'bad', hint: 'Plus d’achat depuis longtemps' },
};

export const STAGE_ORDER: Stage[] = ['contact', 'prospect', 'new', 'active', 'at_risk', 'churned'];

/** Pastille du statut VIP d'une boutique. */
export const VIP_PILL = { label: 'VIP', pill: 'accent', hint: 'Top acheteurs de la boutique sur la période VIP' };

export function stageInfo(stage: string) {
    // « vip » : ancienne étape du Groupe, encore présente dans l'historique.
    if (stage === 'vip') {
        return { label: 'VIP (ancienne étape)', pill: 'accent', hint: '' };
    }

    return STAGES[stage as Stage] ?? { label: stage, pill: 'idle', hint: '' };
}
