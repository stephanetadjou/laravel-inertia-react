import type React from 'react';
// Emblème propre à chaque famille de métiers, au centre du visuel de tête
// (repère 520 x 520, centre 260 260). Dessins vectoriels, palette de la marque.

const NAVY = '#0B2D4A';
const DEEP = '#071E33';
const BLUE = '#0E7BD6';
const GREEN = '#00C896';

function Beaute() {
  const bulbs = Array.from({ length: 14 }, (_, i) => (i * 360) / 14);
  return (
    <g>
      <defs>
        <linearGradient id="be-glass" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#F2F9FF" />
          <stop offset=".6" stopColor="#CFE6F8" />
          <stop offset="1" stopColor="#A9D8E8" />
        </linearGradient>
        <linearGradient id="be-frame" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={BLUE} />
          <stop offset="1" stopColor={GREEN} />
        </linearGradient>
        <linearGradient id="be-stone" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#6E8BA3" />
          <stop offset="1" stopColor={NAVY} />
        </linearGradient>
      </defs>
      <circle cx="260" cy="250" r="138" fill="none" stroke="url(#be-frame)" strokeWidth="16" />
      <circle cx="260" cy="250" r="128" fill="url(#be-glass)" />
      <path d="M170 200 L250 140 M180 240 L290 150" stroke="#fff" strokeWidth="14" strokeLinecap="round" opacity=".55" />
      {bulbs.map((a) => (
        <g key={a} transform={`rotate(${a} 260 250)`}>
          <circle cx="260" cy="88" r="17" fill="#FFE7A3" opacity=".35" />
          <circle cx="260" cy="88" r="9" fill="#FFF7DC" stroke="#F2D47A" strokeWidth="2" />
        </g>
      ))}
      {/* ciseaux dans le miroir */}
      <g transform="translate(260 262) rotate(-35)" fill="none" stroke={NAVY} strokeWidth="9" strokeLinecap="round">
        <circle cx="-26" cy="58" r="20" />
        <circle cx="26" cy="58" r="20" />
        <path d="M-14 42 L18 -72" />
        <path d="M14 42 L-18 -72" />
      </g>
      <circle cx="260" cy="250" r="6" fill={GREEN} />
      {/* galets de spa */}
      <g transform="translate(118 420)">
        <ellipse cx="0" cy="40" rx="62" ry="20" fill="url(#be-stone)" />
        <ellipse cx="0" cy="10" rx="46" ry="17" fill="#3E6584" />
        <ellipse cx="0" cy="-16" rx="32" ry="13" fill="#7FA2BC" />
        <path d="M18 -26 q34 -48 74 -40 q-14 40 -74 40z" fill={GREEN} />
        <path d="M18 -26 q30 -24 60 -34" stroke="#007A5C" strokeWidth="2" fill="none" />
      </g>
    </g>
  );
}

function Sante() {
  return (
    <g>
      <defs>
        <linearGradient id="sa-screen" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={NAVY} />
          <stop offset="1" stopColor={DEEP} />
        </linearGradient>
        <linearGradient id="sa-cross" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#2FE0B4" />
          <stop offset="1" stopColor={BLUE} />
        </linearGradient>
        <filter id="sa-glow" x="-20%" y="-50%" width="140%" height="200%">
          <feGaussianBlur stdDeviation="4" result="b" />
          <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
        </filter>
      </defs>
      <rect x="120" y="160" width="280" height="190" rx="28" fill="url(#sa-screen)" />
      {[200, 240, 280, 320].map((y) => <line key={y} x1="140" x2="380" y1={y} y2={y} stroke="#fff" strokeOpacity=".06" />)}
      <polyline points="140,265 190,265 208,265 222,225 240,315 258,195 276,290 290,265 340,265 352,248 366,265 380,265" fill="none" stroke={GREEN} strokeWidth="5" strokeLinejoin="round" strokeLinecap="round" filter="url(#sa-glow)" />
      <text x="146" y="194" fontSize="15" fontWeight="700" fill="#8FE9CF" fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">72 bpm</text>
      {/* croix médicale */}
      <g transform="translate(372 150)">
        <circle r="46" fill="#fff" />
        <circle r="46" fill="none" stroke="#DCE6EF" strokeWidth="2" />
        <rect x="-11" y="-28" width="22" height="56" rx="5" fill="url(#sa-cross)" />
        <rect x="-28" y="-11" width="56" height="22" rx="5" fill="url(#sa-cross)" />
      </g>
      {/* stéthoscope */}
      <path d="M150 360 q-30 60 20 80 q30 10 50 -4" fill="none" stroke={BLUE} strokeWidth="10" strokeLinecap="round" />
      <circle cx="244" cy="430" r="24" fill="#fff" stroke={BLUE} strokeWidth="9" />
      <circle cx="244" cy="430" r="9" fill={GREEN} />
      <circle cx="150" cy="356" r="9" fill={NAVY} />
    </g>
  );
}

function Sport() {
  return (
    <g>
      <defs>
        <radialGradient id="sp-bell" cx="38%" cy="32%" r="75%">
          <stop offset="0" stopColor="#3D8FD9" />
          <stop offset=".55" stopColor={NAVY} />
          <stop offset="1" stopColor={DEEP} />
        </radialGradient>
      </defs>
      {/* anneaux d'activité */}
      <circle cx="260" cy="260" r="186" fill="none" stroke={GREEN} strokeOpacity=".15" strokeWidth="16" />
      <circle cx="260" cy="260" r="186" fill="none" stroke={GREEN} strokeWidth="16" strokeLinecap="round" strokeDasharray={`${2 * Math.PI * 186 * 0.78} 2000`} transform="rotate(-90 260 260)" />
      <circle cx="260" cy="260" r="162" fill="none" stroke={BLUE} strokeOpacity=".15" strokeWidth="16" />
      <circle cx="260" cy="260" r="162" fill="none" stroke={BLUE} strokeWidth="16" strokeLinecap="round" strokeDasharray={`${2 * Math.PI * 162 * 0.42} 2000`} transform="rotate(-90 260 260)" />
      {/* kettlebell */}
      <path d="M208 236 q0 -100 52 -100 q52 0 52 100" fill="none" stroke={NAVY} strokeWidth="26" strokeLinecap="round" />
      <path d="M208 236 q0 -100 52 -100 q52 0 52 100" fill="none" stroke="#3D8FD9" strokeWidth="6" strokeLinecap="round" opacity=".5" transform="translate(-4 -4)" />
      <circle cx="260" cy="292" r="96" fill="url(#sp-bell)" />
      <ellipse cx="226" cy="250" rx="26" ry="16" fill="#fff" opacity=".22" transform="rotate(-30 226 250)" />
      <rect x="232" y="276" width="56" height="32" rx="8" fill="#fff" opacity=".12" />
      <text x="260" y="299" textAnchor="middle" fontSize="18" fontWeight="700" fill="#fff" fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">16 kg</text>
      <ellipse cx="260" cy="396" rx="80" ry="8" fill={NAVY} opacity=".15" />
    </g>
  );
}

function Technique() {
  const tread = Array.from({ length: 40 }, (_, i) => i * 9);
  const spokes = [0, 72, 144, 216, 288];
  const teeth = Array.from({ length: 10 }, (_, i) => i * 36);
  return (
    <g>
      <defs>
        <radialGradient id="te-rim" cx="40%" cy="35%" r="70%">
          <stop offset="0" stopColor="#F4F8FB" />
          <stop offset=".6" stopColor="#B9CCDC" />
          <stop offset="1" stopColor="#7E97AD" />
        </radialGradient>
        <linearGradient id="te-gear" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#2FE0B4" />
          <stop offset="1" stopColor="#007A5C" />
        </linearGradient>
      </defs>
      {/* engrenage */}
      <g transform="translate(384 146)">
        {teeth.map((a) => <rect key={a} x="-9" y="-66" width="18" height="22" rx="4" fill="url(#te-gear)" transform={`rotate(${a})`} />)}
        <circle r="50" fill="url(#te-gear)" />
        <circle r="18" fill="#F7FAFD" />
      </g>
      {/* pneu et jante */}
      <circle cx="250" cy="276" r="140" fill="#14283C" />
      {tread.map((a) => <rect key={a} x="246" y="134" width="8" height="16" rx="2" fill="#24405A" transform={`rotate(${a} 250 276)`} />)}
      <circle cx="250" cy="276" r="100" fill="#0C1C2C" />
      <circle cx="250" cy="276" r="88" fill="url(#te-rim)" />
      {spokes.map((a) => <path key={a} d="M240 270 L236 200 q14 -8 28 0 L260 270z" fill={NAVY} transform={`rotate(${a} 250 276)`} />)}
      <circle cx="250" cy="276" r="26" fill={NAVY} />
      <circle cx="250" cy="276" r="10" fill={BLUE} />
      {/* clé */}
      <g transform="translate(46 478) rotate(-38)">
        <rect x="0" y="-9" width="150" height="18" rx="9" fill={BLUE} />
        <path d="M150 -26 a26 26 0 1 1 0 52 l0 -16 l16 0 l0 -20 l-16 0z" fill={BLUE} />
      </g>
    </g>
  );
}

function Pro() {
  const rows = [0, 1, 2, 3];
  return (
    <g>
      <defs>
        <linearGradient id="pr-folder" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={BLUE} />
          <stop offset="1" stopColor={NAVY} />
        </linearGradient>
      </defs>
      <g transform="rotate(-6 260 260)">
        <path d="M120 150 h90 l22 22 h168 a14 14 0 0 1 14 14 v196 a14 14 0 0 1 -14 14 h-266 a14 14 0 0 1 -14 -14 v-218 a14 14 0 0 1 14 -14z" fill="url(#pr-folder)" />
        <rect x="140" y="124" width="220" height="250" rx="12" fill="#fff" stroke="#DCE6EF" transform="rotate(4 250 250)" />
        <rect x="150" y="136" width="220" height="250" rx="12" fill="#fff" stroke="#DCE6EF" />
        <text x="174" y="176" fontSize="17" fontWeight="700" fill={NAVY} fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">Dossier Bilan 2026</text>
        {rows.map((i) => (
          <g key={i} transform={`translate(174 ${206 + i * 40})`}>
            <circle cx="10" cy="0" r="11" fill={i < 2 ? GREEN : '#fff'} stroke={i < 2 ? GREEN : '#C2410C'} strokeWidth="2.5" />
            {i < 2 && <path d="M5 0 l4 4 l7 -8" stroke="#fff" strokeWidth="3" fill="none" strokeLinecap="round" strokeLinejoin="round" />}
            <rect x="32" y="-5" width={[120, 96, 132, 84][i]} height="10" rx="5" fill="#DCE6EF" />
          </g>
        ))}
      </g>
      {/* carte de suivi */}
      <g transform="translate(318 300)">
        <rect width="150" height="112" rx="16" fill="#fff" stroke="#DCE6EF" />
        {[34, 52, 44, 70].map((h, i) => <rect key={i} x={20 + i * 30} y={88 - h} width="18" height={h} rx="4" fill={i === 3 ? GREEN : BLUE} opacity={i === 3 ? 1 : 0.75} />)}
        <path d="M24 46 L56 30 L86 38 L120 14" stroke={NAVY} strokeWidth="3" fill="none" strokeLinecap="round" strokeLinejoin="round" />
      </g>
    </g>
  );
}

function Commerce() {
  // Sac de courses et anneau des segments clients.
  const segs: [number, string][] = [[0.34, GREEN], [0.24, BLUE], [0.18, '#7FB8E6'], [0.14, '#C2410C'], [0.1, '#DCE6EF']];
  let a = -Math.PI / 2;
  const arcs = segs.map(([part, color], i) => {
    const b = a + part * Math.PI * 2;
    const r = 66;
    const p = (t: number) => `${(392 + r * Math.cos(t)).toFixed(1)} ${(146 + r * Math.sin(t)).toFixed(1)}`;
    const d = `M ${p(a)} A ${r} ${r} 0 ${part > 0.5 ? 1 : 0} 1 ${p(b - 0.04)}`;
    a = b;
    return <path key={i} d={d} stroke={color} strokeWidth="22" fill="none" />;
  });
  return (
    <g>
      <defs>
        <linearGradient id="co-bag" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={BLUE} />
          <stop offset="1" stopColor={NAVY} />
        </linearGradient>
      </defs>
      <path d="M200 196 v-34 a60 60 0 0 1 120 0 v34" fill="none" stroke={NAVY} strokeWidth="16" strokeLinecap="round" />
      <path d="M142 190 h236 l-18 222 a20 20 0 0 1 -20 18 h-160 a20 20 0 0 1 -20 -18z" fill="url(#co-bag)" />
      <path d="M150 190 h220" stroke={DEEP} strokeWidth="6" opacity=".35" />
      <circle cx="200" cy="232" r="9" fill={DEEP} />
      <circle cx="320" cy="232" r="9" fill={DEEP} />
      <path d="M232 322 l22 22 l44 -50" stroke={GREEN} strokeWidth="16" fill="none" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="392" cy="146" r="92" fill="#fff" stroke="#DCE6EF" />
      {arcs}
      <text x="392" y="141" textAnchor="middle" fontSize="15" fontWeight="700" fill={NAVY} fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">8</text>
      <text x="392" y="160" textAnchor="middle" fontSize="11" fontWeight="600" fill="#4A5B6E" fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">segments</text>
    </g>
  );
}

const arts: Record<string, () => React.ReactElement> = { beaute: Beaute, sante: Sante, sport: Sport, technique: Technique, pro: Pro, commerce: Commerce };

export function SectorEmblem({ slug }: { slug: string }) {
  const Art = arts[slug];
  return Art ? <Art /> : null;
}
