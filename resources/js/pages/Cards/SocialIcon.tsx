/* Icônes rondes des réseaux sociaux d'une carte digitale : couleur officielle de chaque réseau (site web et autres liens : couleur de la carte). */

const PATHS: Record<string, string> = {
    facebook: 'M14 8h3V4h-3c-2.8 0-4 1.8-4 4.3V10H7v4h3v8h4v-8h3l1-4h-4V8.6c0-.4.3-.6.6-.6Z',
    instagram: 'M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4Zm5 5a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm5.5-1.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z',
    tiktok: 'M16 3c.4 2.3 1.8 3.8 4 4v3.2c-1.5 0-2.9-.5-4-1.3V15a6 6 0 1 1-6-6v3.3A2.7 2.7 0 1 0 12.7 15V3H16Z',
    linkedin: 'M4 9h4v12H4V9Zm2-6a2.2 2.2 0 1 1 0 4.4A2.2 2.2 0 0 1 6 3Zm5 6h3.8v1.7c.6-1 1.9-2 3.9-2 4 0 4.3 2.6 4.3 6V21h-4v-5.6c0-1.4 0-3.1-1.9-3.1-2 0-2.2 1.5-2.2 3V21H11V9Z',
    youtube: 'M22 8.2c-.2-1.7-1-2.8-2.8-3C16.6 5 12 5 12 5s-4.6 0-7.2.2c-1.8.2-2.6 1.3-2.8 3C1.8 9.6 1.8 12 1.8 12s0 2.4.2 3.8c.2 1.7 1 2.8 2.8 3 2.6.2 7.2.2 7.2.2s4.6 0 7.2-.2c1.8-.2 2.6-1.3 2.8-3 .2-1.4.2-3.8.2-3.8s0-2.4-.2-3.8ZM10 15.3V8.7l5.5 3.3-5.5 3.3Z',
    x: 'M17.8 3h3.1l-6.8 7.8L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.3-8.3L2 3h6.4l4.4 5.8L17.8 3Zm-1.1 16.2h1.7L7.4 4.7H5.6l11.1 14.5Z',
    link: 'M10.6 13.4a1 1 0 0 1 0-1.4l3.5-3.5a3 3 0 0 1 4.2 4.2l-2 2a1 1 0 1 1-1.4-1.4l2-2a1 1 0 0 0-1.4-1.4L12 13.4a1 1 0 0 1-1.4 0Zm2.8-2.8a1 1 0 0 1 0 1.4l-3.5 3.5a3 3 0 0 1-4.2-4.2l2-2a1 1 0 1 1 1.4 1.4l-2 2a1 1 0 0 0 1.4 1.4l3.5-3.5a1 1 0 0 1 1.4 0Z',
};

/** Couleurs officielles des réseaux (fond) ; Snapchat garde une icône noire. */
export const BRAND_COLORS: Record<string, string> = {
    whatsapp: '#25D366',
    facebook: '#1877F2',
    instagram: 'linear-gradient(45deg, #F58529, #DD2A7B, #8134AF, #515BD4)',
    tiktok: '#000000',
    linkedin: '#0A66C2',
    youtube: '#FF0000',
    x: '#000000',
    twitter: '#000000',
    snapchat: '#FFFC00',
    telegram: '#26A5E4',
    messenger: '#0084FF',
    pinterest: '#E60023',
};

export interface SocialLink {
    key: string;
    label: string;
    url: string;
}

export default function SocialIcon({ name, className = 'h-5 w-5' }: { name: string; className?: string }) {
    return (
        <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden="true">
            <path d={PATHS[name] ?? PATHS.link} fillRule="evenodd" />
        </svg>
    );
}

/** Rangée d'icônes rondes ; onClick sert à compter le clic (page publique). */
export function SocialRow({ links, color, size = 'h-11 w-11', onClick }: { links: SocialLink[]; color: string; size?: string; onClick?: () => void }) {
    if (links.length === 0) return null;
    return (
        <div className="flex flex-wrap justify-center gap-2.5">
            {links.map((s) => (
                <a key={s.key} href={s.url} target="_blank" rel="noopener noreferrer" onClick={onClick} aria-label={s.label} title={s.label} className={`grid ${size} place-items-center rounded-full transition hover:opacity-90 ${s.key === 'snapchat' ? 'text-black' : 'text-white'}`} style={{ background: BRAND_COLORS[s.key] ?? color }}>
                    <SocialIcon name={s.key} />
                </a>
            ))}
        </div>
    );
}
