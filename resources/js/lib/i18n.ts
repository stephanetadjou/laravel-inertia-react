import { router } from '@inertiajs/react';

/**
 * Traductions de l'interface. Les textes sont écrits en français dans le code ;
 * en anglais, t('Texte français') renvoie la traduction de lang/en.json
 * (partagée par Inertia), sinon le texte français tel quel.
 * Remplacements : t('Bonjour :name', { name: 'Awa' }).
 */
export type Locale = 'fr' | 'en';

let current: Locale = 'fr';
let dictionary: Record<string, string> = {};

type SharedProps = { locale?: Locale; translations?: Record<string, string> };

export function setI18n(props: SharedProps | undefined): void {
    if (!props) return;
    if (props.locale) current = props.locale;
    // Les traductions sont partagées à chaque visite ; une réponse partielle peut ne pas les contenir.
    if (props.translations !== undefined) dictionary = props.translations ?? {};
    if (typeof document !== 'undefined') document.documentElement.lang = current;
}

/** À appeler une fois : suit les changements de page (et de langue) d'Inertia. */
export function initI18n(initial: SharedProps | undefined): void {
    setI18n(initial);
    router.on('navigate', (event) => setI18n(event.detail.page.props as SharedProps));
}

export function locale(): Locale {
    return current;
}

/** Locale des nombres et des dates (Intl, toLocaleString). */
export function intlLocale(): string {
    return current === 'en' ? 'en-GB' : 'fr-FR';
}

export function t(text: string, replace?: Record<string, string | number>): string {
    let out = (current === 'en' && dictionary[text]) || text;
    if (replace) {
        for (const [k, v] of Object.entries(replace)) out = out.split(`:${k}`).join(String(v));
    }
    return out;
}

/** Choisit la langue de l'interface (enregistrée sur l'utilisateur, ou en session). */
export function switchLocale(to: Locale): void {
    router.put('/locale', { locale: to }, { preserveScroll: true });
}
