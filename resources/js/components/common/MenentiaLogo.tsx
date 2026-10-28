import { useId } from 'react';

/**
 * Logo Menentia : carré arrondi en dégradé vert (#00C896) vers bleu
 * (#0E7BD6) avec un « m » blanc, suivi du mot « menentia » (bleu nuit sur
 * fond clair, blanc sur fond sombre). Mêmes tracés que public/favicon.svg.
 */
export function MenentiaSymbol({ className = 'h-9 w-9' }: { className?: string }) {
    // Identifiant de dégradé propre à chaque logo affiché (plusieurs par page).
    const gradient = `menentia-gradient-${useId().replace(/:/g, '')}`;

    return (
        <svg viewBox="0 0 100 100" className={`shrink-0 ${className}`} aria-hidden="true">
            <defs>
                <linearGradient id={gradient} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#00C896" />
                    <stop offset="1" stopColor="#0E7BD6" />
                </linearGradient>
            </defs>
            <rect width="100" height="100" rx="28" fill={`url(#${gradient})`} />
            <path
                d="M25 74 V46 A12.5 12.5 0 0 1 50 46 V74 M50 46 A12.5 12.5 0 0 1 75 46 V74"
                fill="none"
                stroke="#FFFFFF"
                strokeWidth="11"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function MenentiaWordmark({ onDark = false, className = 'text-xl' }: { onDark?: boolean; className?: string }) {
    return (
        <span className={`font-display leading-none font-extrabold tracking-tight ${onDark ? 'text-white' : 'text-ink-strong dark:text-white'} ${className}`}>
            menentia
        </span>
    );
}

export default function MenentiaLogo({ onDark = false, symbolClassName, wordmarkClassName }: { onDark?: boolean; symbolClassName?: string; wordmarkClassName?: string }) {
    return (
        <span className="flex items-center gap-2.5">
            <MenentiaSymbol className={symbolClassName} />
            <MenentiaWordmark onDark={onDark} className={wordmarkClassName} />
        </span>
    );
}
