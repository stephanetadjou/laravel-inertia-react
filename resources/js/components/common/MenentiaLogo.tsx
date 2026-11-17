/**
 * Logo ClientlyX « Piste B » (15/11/2026, même charte que le site clientlyx.com) :
 * hexagone prune #2B1A3D, « C » orange #E8590C et flèche jaune #FFD43B
 * (sur fond sombre : hexagone #3D2757 cerné d'orange) ; nom
 * « Cliently » en prune #2B1A3D (blanc sur fond sombre) avec le X orange.
 * Les noms d'export ClientlyX* sont gardés pour ne pas toucher aux écrans.
 */

export function MenentiaSymbol({ className = 'h-9 w-9', onDark: dark = false }: { className?: string; onDark?: boolean }) {
    return (
        <svg viewBox="0 0 100 100" className={`shrink-0 ${className}`} aria-hidden="true">
              <polygon points="50,4 89.8,27 89.8,73 50,96 10.2,73 10.2,27" fill={dark ? '#3D2757' : '#2B1A3D'} stroke={dark ? '#E8590C' : 'none'} strokeWidth={dark ? 4 : 0} strokeLinejoin="round" />
              <path d="M63 35 A20 20 0 1 0 63 65" fill="none" stroke="#E8590C" strokeWidth="10" strokeLinecap="round" />
              <path d="M62 27 L74 27 L74 39" fill="none" stroke="#FFD43B" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" />
              <path d="M60 41 L73 28" stroke="#FFD43B" strokeWidth="5" strokeLinecap="round" />
        </svg>
    );
}

/** Nom ClientlyX : « Cliently » + X orange. */
export function MenentiaWordmark({ onDark = false, className = 'text-xl' }: { onDark?: boolean; className?: string }) {
    return (
        <span className={`inline-flex items-center font-extrabold leading-none tracking-[-0.02em] ${onDark ? 'text-white' : 'text-[#2B1A3D] dark:text-white'} ${className}`} aria-label="ClientlyX">
            Cliently<span className="text-[#E8590C]">X</span>
        </span>
    );
}

export default function MenentiaLogo({ onDark = false, symbolClassName, wordmarkClassName }: { onDark?: boolean; symbolClassName?: string; wordmarkClassName?: string }) {
    return (
        <span className="flex items-center gap-2.5">
            <MenentiaSymbol className={symbolClassName} onDark={onDark} />
            <MenentiaWordmark onDark={onDark} className={wordmarkClassName} />
        </span>
    );
}
