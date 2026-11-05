/**
 * Logo NENTIX (charte du 05/11/2026) : hexagone bleu #1D4ED8 évidé, cœur
 * cyan #06B6D4, nom NENTIX vectorisé (Outfit Bold) en bleu nuit #0B1F3A.
 * Sur fond sombre : hexagone blanc, cœur cyan, texte blanc.
 * Les noms d'export Menentia* sont gardés pour ne pas toucher aux écrans.
 */

const HEX_OUTER = 'M50,0 93.3,25 93.3,75 50,100 6.7,75 6.7,25Z M50,15 80.31,32.5 80.31,67.5 50,85 19.69,67.5 19.69,32.5Z';
const HEX_CORE = 'M50,32 65.59,41 65.59,59 50,68 34.41,59 34.41,41Z';
const WORDMARK =
    'M4.81586402266289 0.0V-50.0H12.606232294617564L15.93484419263456 -40.01416430594901V0.0ZM39.30594900849859 0.0 9.844192634560907 -37.74787535410765 12.606232294617564 -50.0 42.067988668555245 -12.252124645892351ZM39.30594900849859 0.0 36.331444759206796 -9.985835694050992V-50.0H47.450424929178475V0.0ZM58.082152974504254 0.0V-50.0H69.20113314447592V0.0ZM66.58073654390935 0.0V-9.63172804532578H93.98866855524079V0.0ZM66.58073654390935 -20.892351274787536V-30.1699716713881H91.5099150141643V-20.892351274787536ZM66.58073654390935 -40.43909348441927V-50.0H93.63456090651559V-40.43909348441927ZM102.63739376770539 0.0V-50.0H110.42776203966007L113.75637393767705 -40.01416430594901V0.0ZM137.12747875354108 0.0 107.66572237960341 -37.74787535410765 110.42776203966007 -50.0 139.88951841359773 -12.252124645892351ZM137.12747875354108 0.0 134.1529745042493 -9.985835694050992V-50.0H145.27195467422098V0.0ZM168.1558073654391 0.0V-49.29178470254958H179.27478753541078V0.0ZM152.5042492917847 -40.29745042492918V-50.0H194.92634560906515V-40.29745042492918ZM202.1586402266289 0.0V-50.0H213.27762039660058V0.0ZM255.6373937677054 0.0 241.68555240793202 -22.308781869688385H240.33994334277622L221.07648725212465 -50.0H234.3201133144476L248.05949008498584 -29.036827195467424H249.40509915014164L268.66855524079324 0.0ZM220.1558073654391 0.0 238.6402266288952 -26.770538243626063 247.20963172804534 -21.954674220963174 232.8328611898017 0.0ZM249.8300283286119 -23.58356940509915 241.26062322946177 -28.39943342776204 255.1416430594901 -50.0H267.8186968838527Z';

export function MenentiaSymbol({ className = 'h-9 w-9', onDark = false }: { className?: string; onDark?: boolean }) {
    return (
        <svg viewBox="0 0 100 100" className={`shrink-0 ${className}`} aria-hidden="true">
            <path d={HEX_OUTER} fill={onDark ? '#FFFFFF' : '#1D4ED8'} fillRule="evenodd" />
            <path d={HEX_CORE} fill="#06B6D4" />
        </svg>
    );
}

/** Nom NENTIX vectorisé (jamais retapé), hauteur = taille de police. */
export function MenentiaWordmark({ onDark = false, className = 'text-xl' }: { onDark?: boolean; className?: string }) {
    return (
        <span className={`inline-flex items-center leading-none ${onDark ? 'text-white' : 'text-[#0B1F3A] dark:text-white'} ${className}`}>
            <svg viewBox="108 25 266 50" style={{ height: '0.8em', width: 'auto' }} role="img" aria-label="NENTIX">
                <path transform="translate(103.78,75)" d={WORDMARK} fill="currentColor" />
            </svg>
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
