/**
 * Logo Menentia : carré arrondi rouge avec un « m » blanc et un point
 * anthracite, suivi du mot « menentia » (rouge sur fond clair, blanc sur le
 * menu anthracite). Mêmes tracés que public/favicon.svg.
 */
export function MenentiaSymbol({ className = 'h-9 w-9' }: { className?: string }) {
    return (
        <svg viewBox="0 0 100 100" className={`shrink-0 ${className}`} aria-hidden="true">
            <rect width="100" height="100" rx="28" fill="#EF4444" />
            <path
                d="M27 77 V52 A11.5 11.5 0 0 1 50 52 V77 M50 52 A11.5 11.5 0 0 1 73 52 V77"
                fill="none"
                stroke="#FFFFFF"
                strokeWidth="10.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <circle cx="50" cy="24" r="8" fill="#1F2937" />
        </svg>
    );
}

export function MenentiaWordmark({ onDark = false, className = 'text-xl' }: { onDark?: boolean; className?: string }) {
    return (
        <span className={`font-display leading-none font-extrabold tracking-tight ${onDark ? 'text-white' : 'text-brand'} ${className}`}>
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
