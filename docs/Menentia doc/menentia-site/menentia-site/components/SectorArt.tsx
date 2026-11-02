import type React from 'react';
// Illustrations vectorielles des pages secteurs (aucune photo externe).
// Personnages stylisés, palette de la marque. Une photo placée dans
// public/images/secteurs/<slug>.jpg remplace l’illustration (voir la page secteur).

const C = {
  navy: '#0B2D4A',
  deep: '#071E33',
  blue: '#0E7BD6',
  blueSoft: '#CFE3F7',
  green: '#00C896',
  greenSoft: '#C9F1E4',
  line: '#DCE6EF',
  wall: '#F4F8FB',
  white: '#FFFFFF',
  wood: '#C99A6B',
  woodDark: '#A97C50',
  hair: '#1A1410',
};
const SKIN = ['#7A4A2E', '#5C3620', '#8D5A3B', '#6B3F25'];

type P = { x: number; y: number; skin: string; top: string; pants?: string; hair?: 'short' | 'bun' | 'braids' | 'wrap'; wrap?: string; s?: number };

// Personnage debout ; (x, y) = point entre les pieds.
function Standing({ x, y, skin, top, pants = C.navy, hair = 'short', wrap = C.green, s = 1 }: P) {
  return (
    <g transform={`translate(${x} ${y}) scale(${s})`}>
      <rect x={-19} y={-112} width={16} height={108} rx={7} fill={pants} />
      <rect x={3} y={-112} width={16} height={108} rx={7} fill={pants} />
      <ellipse cx={-11} cy={-3} rx={13} ry={5} fill={C.deep} />
      <ellipse cx={11} cy={-3} rx={13} ry={5} fill={C.deep} />
      <rect x={-49} y={-200} width={15} height={84} rx={7.5} fill={top} />
      <rect x={34} y={-200} width={15} height={84} rx={7.5} fill={top} />
      <circle cx={-41.5} cy={-112} r={8} fill={skin} />
      <circle cx={41.5} cy={-112} r={8} fill={skin} />
      <path d="M-36 -206 q36 -16 72 0 v100 h-72z" fill={top} />
      <rect x={-7} y={-226} width={14} height={22} rx={5} fill={skin} />
      <circle cx={0} cy={-246} r={24} fill={skin} />
      <Hair kind={hair} wrap={wrap} />
    </g>
  );
}

// Personnage assis de face ; (x, y) = hauteur de l’assise.
function Seated({ x, y, skin, top, pants = C.navy, hair = 'short', wrap = C.green, s = 1 }: P) {
  return (
    <g transform={`translate(${x} ${y}) scale(${s})`}>
      <rect x={-30} y={-8} width={60} height={22} rx={10} fill={pants} />
      <rect x={-28} y={8} width={16} height={70} rx={7} fill={pants} />
      <rect x={12} y={8} width={16} height={70} rx={7} fill={pants} />
      <ellipse cx={-20} cy={78} rx={13} ry={5} fill={C.deep} />
      <ellipse cx={20} cy={78} rx={13} ry={5} fill={C.deep} />
      <path d="M-34 -98 q34 -16 68 0 v92 h-68z" fill={top} />
      <rect x={-46} y={-92} width={14} height={70} rx={7} fill={top} />
      <rect x={32} y={-92} width={14} height={70} rx={7} fill={top} />
      <circle cx={-39} cy={-20} r={7.5} fill={skin} />
      <circle cx={39} cy={-20} r={7.5} fill={skin} />
      <rect x={-7} y={-118} width={14} height={22} rx={5} fill={skin} />
      <g transform="translate(0 108)">
        <circle cx={0} cy={-246} r={24} fill={skin} />
        <Hair kind={hair} wrap={wrap} />
      </g>
    </g>
  );
}

function Hair({ kind, wrap }: { kind: P['hair']; wrap: string }) {
  if (kind === 'bun')
    return (
      <g fill={C.hair}>
        <path d="M-25 -246 a25 25 0 0 1 50 0 q-6 -12 -25 -13 q-19 1 -25 13z" />
        <circle cx={0} cy={-276} r={12} />
      </g>
    );
  if (kind === 'braids')
    return (
      <g fill={C.hair}>
        <path d="M-26 -244 a26 26 0 0 1 52 0 q-8 -10 -26 -11 q-18 1 -26 11z" />
        <rect x={-30} y={-250} width={8} height={62} rx={4} />
        <rect x={22} y={-250} width={8} height={62} rx={4} />
      </g>
    );
  if (kind === 'wrap')
    return <path d="M-27 -250 q-2 -34 27 -36 q29 2 27 36 q-10 -10 -27 -10 q-17 0 -27 10z" fill={wrap} />;
  return <path d="M-24 -250 a24 24 0 0 1 48 0 q-8 -8 -24 -8 q-16 0 -24 8z" fill={C.hair} />;
}

// Bulle de notification façon application, posée sur la scène.
function Chip({ x, y, title, text, tone = 'green', w = 230 }: { x: number; y: number; title: string; text: string; tone?: 'green' | 'blue' | 'warn'; w?: number }) {
  const dot = tone === 'green' ? C.green : tone === 'blue' ? C.blue : '#C2410C';
  return (
    <g transform={`translate(${x} ${y})`}>
      <rect x={0} y={4} width={w} height={62} rx={14} fill={C.navy} opacity={0.12} />
      <rect x={0} y={0} width={w} height={62} rx={14} fill={C.white} stroke={C.line} />
      <circle cx={24} cy={31} r={12} fill={dot} opacity={0.15} />
      <circle cx={24} cy={31} r={5} fill={dot} />
      <text x={46} y={26} fontSize={13} fontWeight={700} fill={C.navy} fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">{title}</text>
      <text x={46} y={45} fontSize={12} fill="#4A5B6E" fontFamily="Inter, ui-sans-serif, system-ui, sans-serif">{text}</text>
    </g>
  );
}

function Frame({ children, id, label }: { children: React.ReactNode; id: string; label: string }) {
  return (
    <svg viewBox="0 0 600 450" role="img" aria-label={label} className="h-auto w-full">
      <defs>
        <linearGradient id={`bg-${id}`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#EAF3FB" />
          <stop offset="1" stopColor="#E3F8F1" />
        </linearGradient>
      </defs>
      <rect width={600} height={450} fill={`url(#bg-${id})`} />
      <rect y={372} width={600} height={78} fill="#DCE9F2" />
      <rect y={372} width={600} height={3} fill={C.line} />
      {children}
    </svg>
  );
}

function Plant({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <path d="M0 -40 q-30 -30 -14 -70 q10 30 14 70z" fill={C.green} />
      <path d="M0 -40 q28 -26 18 -64 q-14 26 -18 64z" fill="#00A97F" />
      <path d="M0 -40 q-6 -40 4 -86 q8 40 -4 86z" fill="#14B88D" />
      <path d="M-18 -40 h36 l-5 40 h-26z" fill={C.navy} />
    </g>
  );
}

function Beaute() {
  return (
    <Frame id="beaute" label="Une coiffeuse réalise des tresses pour une cliente dans un salon">
      <rect x={60} y={60} width={200} height={14} rx={7} fill={C.white} />
      <circle cx={330} cy={150} r={92} fill={C.white} stroke={C.line} strokeWidth={8} />
      <circle cx={330} cy={150} r={80} fill={C.blueSoft} opacity={0.6} />
      <path d="M270 120 q30 -40 70 -30" stroke={C.white} strokeWidth={10} strokeLinecap="round" fill="none" opacity={0.8} />
      <rect x={460} y={210} width={90} height={160} rx={10} fill={C.white} stroke={C.line} />
      <rect x={472} y={226} width={66} height={4} rx={2} fill={C.line} />
      <rect x={476} y={180} width={16} height={34} rx={5} fill={C.blue} />
      <rect x={498} y={170} width={14} height={44} rx={5} fill={C.green} />
      <rect x={518} y={186} width={18} height={28} rx={6} fill={C.navy} />
      <Plant x={92} y={372} />
      {/* fauteuil */}
      <rect x={262} y={250} width={136} height={110} rx={26} fill={C.navy} />
      <rect x={250} y={290} width={22} height={60} rx={10} fill={C.deep} />
      <rect x={388} y={290} width={22} height={60} rx={10} fill={C.deep} />
      <rect x={322} y={356} width={16} height={16} fill={C.deep} />
      <ellipse cx={330} cy={372} rx={48} ry={6} fill={C.deep} />
      <Seated x={330} y={300} skin={SKIN[0]} top="#E9EEF4" pants={C.blue} hair="braids" s={0.78} />
      <Standing x={200} y={372} skin={SKIN[1]} top={C.green} pants={C.navy} hair="wrap" wrap={C.navy} s={0.95} />
      <Chip x={300} y={28} title="Rappel envoyé" text="Tresses · mardi 10 h · confirmé" />
    </Frame>
  );
}

function Sante() {
  return (
    <Frame id="sante" label="Une médecin reçoit un patient en consultation">
      <rect x={60} y={70} width={150} height={130} rx={12} fill={C.white} stroke={C.line} strokeWidth={6} />
      <rect x={132} y={70} width={6} height={130} fill={C.line} />
      <rect x={60} y={132} width={150} height={6} fill={C.line} />
      <g transform="translate(470 92)">
        <circle r={36} fill={C.white} stroke={C.line} />
        <rect x={-8} y={-22} width={16} height={44} rx={3} fill={C.green} />
        <rect x={-22} y={-8} width={44} height={16} rx={3} fill={C.green} />
      </g>
      {/* bureau */}
      <rect x={250} y={262} width={290} height={16} rx={6} fill={C.navy} />
      <rect x={262} y={278} width={14} height={94} fill={C.deep} />
      <rect x={514} y={278} width={14} height={94} fill={C.deep} />
      <rect x={430} y={204} width={86} height={58} rx={6} fill={C.deep} />
      <rect x={436} y={210} width={74} height={44} rx={3} fill={C.blue} opacity={0.85} />
      <rect x={462} y={254} width={22} height={8} fill={C.deep} />
      <rect x={300} y={252} width={70} height={10} rx={3} fill={C.white} stroke={C.line} />
      <Seated x={130} y={300} skin={SKIN[2]} top={C.blue} pants={C.navy} hair="short" s={0.86} />
      <rect x={90} y={292} width={84} height={14} rx={6} fill={C.woodDark} />
      <Standing x={360} y={372} skin={SKIN[3]} top={C.white} pants={C.navy} hair="bun" s={0.98} />
      <path d="M346 150 q14 34 28 0" stroke={C.navy} strokeWidth={4} fill="none" transform="translate(-6 30)" />
      <circle cx={354} cy={214} r={6} fill={C.navy} />
      <rect x={378} y={170} width={10} height={20} rx={3} fill={C.green} />
      <Chip x={36} y={24} title="Contrôle à 6 mois" text="Rappel prêt · lien de réservation" tone="blue" w={250} />
    </Frame>
  );
}

function Sport() {
  return (
    <Frame id="sport" label="Un coach suit l’entraînement d’une membre dans une salle de sport">
      <rect x={40} y={90} width={520} height={12} rx={6} fill={C.white} />
      <rect x={420} y={170} width={140} height={14} rx={6} fill={C.navy} />
      <rect x={420} y={250} width={140} height={14} rx={6} fill={C.navy} />
      <rect x={430} y={184} width={8} height={188} fill={C.deep} />
      <rect x={542} y={184} width={8} height={188} fill={C.deep} />
      {[440, 470, 500, 530].map((cx, i) => (
        <g key={cx}>
          <rect x={cx - 6} y={150} width={12} height={20} rx={3} fill={i % 2 ? C.green : C.blue} />
          <rect x={cx - 7} y={228} width={14} height={22} rx={3} fill={i % 2 ? C.blue : C.green} />
        </g>
      ))}
      <rect x={120} y={358} width={220} height={14} rx={7} fill={C.green} opacity={0.5} />
      {/* membre qui soulève des haltères */}
      <Standing x={230} y={358} skin={SKIN[0]} top={C.navy} pants="#16466F" hair="bun" s={0.92} />
      {[192, 268].map((cx) => (
        <g key={cx}>
          <rect x={cx - 16} y={250} width={32} height={8} rx={4} fill={C.deep} />
          <rect x={cx - 22} y={240} width={10} height={28} rx={3} fill={C.blue} />
          <rect x={cx + 12} y={240} width={10} height={28} rx={3} fill={C.blue} />
        </g>
      ))}
      <Standing x={380} y={372} skin={SKIN[1]} top={C.green} pants={C.navy} hair="short" s={0.9} />
      <rect x={402} y={226} width={40} height={52} rx={5} fill={C.white} stroke={C.line} />
      <rect x={410} y={238} width={24} height={4} rx={2} fill={C.line} />
      <rect x={410} y={248} width={18} height={4} rx={2} fill={C.line} />
      <Chip x={36} y={24} title="Junior E. · à risque" text="1 séance en 2 semaines" tone="warn" w={220} />
    </Frame>
  );
}

function Technique() {
  return (
    <Frame id="technique" label="Un mécanicien entretient un véhicule dans un garage">
      <rect x={40} y={80} width={190} height={120} rx={10} fill={C.white} stroke={C.line} />
      {[70, 110, 150, 190].map((x, i) => (
        <rect key={x} x={x - 4} y={100 + (i % 2) * 10} width={8} height={70 - (i % 2) * 10} rx={3} fill={i % 2 ? C.blue : C.navy} />
      ))}
      {/* pont élévateur */}
      <rect x={240} y={296} width={300} height={12} rx={4} fill={C.deep} />
      <rect x={262} y={308} width={14} height={64} fill={C.navy} />
      <rect x={504} y={308} width={14} height={64} fill={C.navy} />
      {/* voiture */}
      <path d="M232 284 q2 -40 40 -46 l40 -6 q34 -40 82 -42 h56 q40 2 64 44 q36 6 38 44 v12 h-320z" fill={C.blue} />
      <path d="M326 232 q26 -30 66 -32 h18 v34z" fill={C.blueSoft} />
      <path d="M420 200 h30 q28 4 44 34 h-74z" fill={C.blueSoft} />
      <rect x={232} y={276} width={320} height={10} rx={4} fill="#0A66B2" />
      <circle cx={300} cy={292} r={26} fill={C.deep} />
      <circle cx={300} cy={292} r={11} fill={C.line} />
      <circle cx={484} cy={292} r={26} fill={C.deep} />
      <circle cx={484} cy={292} r={11} fill={C.line} />
      <Standing x={140} y={372} skin={SKIN[2]} top={C.navy} pants={C.navy} hair="short" s={0.96} />
      <rect x={176} y={256} width={50} height={8} rx={4} fill="#9FB3C6" transform="rotate(-35 180 262)" />
      <circle cx={222} cy={232} r={9} fill="none" stroke="#9FB3C6" strokeWidth={5} />
      <Chip x={330} y={36} title="Vidange due" text="Toyota Hilux · 5 000 km" tone="blue" w={210} />
    </Frame>
  );
}

function Pro() {
  return (
    <Frame id="pro" label="Une consultante reçoit un client dans son cabinet">
      <rect x={44} y={70} width={140} height={210} rx={8} fill={C.white} stroke={C.line} />
      {[120, 180, 240].map((y) => (
        <g key={y}>
          <rect x={52} y={y} width={124} height={5} fill={C.line} />
          <rect x={60} y={y - 34} width={14} height={34} fill={C.navy} />
          <rect x={78} y={y - 30} width={12} height={30} fill={C.blue} />
          <rect x={94} y={y - 36} width={16} height={36} fill={C.green} />
          <rect x={114} y={y - 28} width={12} height={28} fill={C.navy} opacity={0.6} />
        </g>
      ))}
      <rect x={420} y={66} width={140} height={96} rx={8} fill={C.white} stroke={C.line} />
      <path d="M436 140 l28 -26 l24 16 l32 -40 l24 18" stroke={C.green} strokeWidth={5} fill="none" strokeLinecap="round" strokeLinejoin="round" />
      {/* table */}
      <Seated x={250} y={300} skin={SKIN[3]} top={C.navy} pants={C.navy} hair="short" s={0.86} />
      <Seated x={470} y={300} skin={SKIN[0]} top={C.green} pants="#16466F" hair="wrap" wrap={C.blue} s={0.86} />
      <rect x={210} y={272} width={300} height={14} rx={6} fill={C.wood} />
      <rect x={226} y={286} width={12} height={86} fill={C.woodDark} />
      <rect x={482} y={286} width={12} height={86} fill={C.woodDark} />
      <rect x={326} y={232} width={70} height={42} rx={4} fill={C.deep} />
      <rect x={316} y={268} width={90} height={6} rx={3} fill={C.navy} />
      <rect x={414} y={262} width={46} height={10} rx={2} fill={C.white} stroke={C.line} />
      <Chip x={196} y={28} title="Dossier Bilan 2026" text="Pièces attendues · relance prête" tone="blue" w={240} />
    </Frame>
  );
}

const scenes: Record<string, () => React.ReactElement> = { beaute: Beaute, sante: Sante, sport: Sport, technique: Technique, pro: Pro };

export function SectorArt({ slug }: { slug: string }) {
  const Scene = scenes[slug];
  return Scene ? <Scene /> : null;
}
