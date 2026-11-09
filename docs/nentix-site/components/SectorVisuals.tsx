// Visuels des pages secteurs : emblème propre au métier, frise « sans suivi / avec NENTIX »
// Tout est dessiné en SVG et CSS, sans photo.

import type { Family } from '@/lib/site';
import { SectorEmblem } from './SectorHero';

// Cadrage des photos de public/images/secteurs (le professionnel reste visible en 4:3).
const focus: Record<string, string> = { beaute: '52% 30%', sante: '42% 40%', sport: '50% 35%', technique: '36% 30%', pro: '72% 35%' };

// photo : scène du métier en paysage. Elle remplace l’emblème dessiné.
export function SectorOrb({ f, photo }: { f: Family; photo?: string }) {
  if (photo) {
    return (
      <div className="relative mx-auto w-full max-w-[560px] pb-6 pt-6">
        <div aria-hidden className="absolute -inset-4 rounded-[2.5rem] bg-gradient-to-br from-blue/15 to-green/20 blur-2xl" />
        <figure className="relative overflow-hidden rounded-[1.75rem] border border-white shadow-float">
          <img src={photo} alt={`${f.name} : un professionnel avec son client`} className="aspect-[4/3] w-full object-cover" style={{ objectPosition: focus[f.slug] ?? '50% 40%' }} />
        </figure>
        <Chip n="1" className="left-0 top-0 sm:-left-6" title={f.chips[1][0]} text={f.chips[1][1]} tone="warn" />
        <Chip n="2" className="bottom-0 right-0 sm:-right-6" title={f.chips[0][0]} text={f.chips[0][1]} tone="green" />
      </div>
    );
  }

  const rings = [250, 214, 180, 150];
  return (
    <div className="relative mx-auto aspect-square w-full max-w-[520px]" aria-hidden>
      <svg viewBox="0 0 520 520" className="absolute inset-0 h-full w-full">
        <defs>
          <radialGradient id={`halo-${f.slug}`} cx="50%" cy="50%" r="50%">
            <stop offset=".5" stopColor="#0E7BD6" stopOpacity=".16" />
            <stop offset="1" stopColor="#0E7BD6" stopOpacity="0" />
          </radialGradient>
        </defs>
        {rings.map((r, i) => (
          <circle key={r} cx="260" cy="260" r={r} fill="none" stroke="#0E7BD6" strokeOpacity={0.08 + i * 0.05} strokeWidth={1} strokeDasharray={i % 2 ? '2 6' : undefined} />
        ))}
        <circle cx="260" cy="260" r="170" fill={`url(#halo-${f.slug})`} />
        <SectorEmblem slug={f.slug} />
        <circle cx="72" cy="300" r="5" fill="#0E7BD6" />
        <circle cx="72" cy="300" r="12" fill="#0E7BD6" fillOpacity=".15" />
        <circle cx="430" cy="110" r="4" fill="#00C896" />
        <circle cx="160" cy="470" r="4" fill="#00C896" fillOpacity=".7" />
      </svg>
      <Chip n="1" className="left-0 top-[12%] sm:-left-4" title={f.chips[1][0]} text={f.chips[1][1]} tone="warn" />
      <Chip n="2" className="bottom-[14%] right-0 sm:-right-2" title={f.chips[0][0]} text={f.chips[0][1]} tone="green" />
    </div>
  );
}

function Chip({ n, title, text, className, tone }: { n: string; title: string; text: string; className: string; tone: 'warn' | 'green' }) {
  return (
    <div className={`absolute flex max-w-[15rem] items-start gap-2.5 rounded-xl border border-line bg-white/95 p-3 shadow-card backdrop-blur ${className}`}>
      <span className={`grid h-6 w-6 shrink-0 place-items-center rounded-full text-[12px] font-bold text-white ${tone === 'warn' ? 'bg-warn' : 'bg-green-text'}`}>{n}</span>
      <span className="min-w-0">
        <span className="block text-[13px] font-bold leading-tight text-navy">{title}</span>
        <span className="block text-[12px] leading-snug text-muted">{text}</span>
      </span>
    </div>
  );
}

export function Timeline({ f }: { f: Family }) {
  return (
    <div className="flex flex-col divide-y divide-line rounded-3xl border border-line bg-white">
      <Track title="Sans suivi" tag="Le client s’éloigne" tone="warn" steps={f.timeline.without} text={f.story.before} />
      <Track title="Avec NENTIX" tag="La relation tient" tone="green" steps={f.timeline.with} text={f.story.after} />
    </div>
  );
}

function Track({ title, tag, tone, steps, text }: { title: string; tag: string; tone: 'warn' | 'green'; steps: [string, string][]; text: string }) {
  const c = tone === 'warn' ? { tag: 'text-warn', line: 'bg-warn', dot: 'border-warn bg-warn' } : { tag: 'text-green-text', line: 'bg-green-text', dot: 'border-green-text bg-green-text' };
  return (
    <div className="flex flex-col gap-6 p-6 sm:p-8">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="font-display text-[18px] font-bold text-navy">{title}</p>
        <p className={`text-[11.5px] font-bold uppercase tracking-[0.18em] ${c.tag}`}>{tag}</p>
      </div>
      <div className="overflow-x-auto">
        <ol className="relative grid min-w-[560px] grid-cols-5">
          <span aria-hidden className="absolute left-[10%] right-[10%] top-[7px] h-px bg-line" />
          <span aria-hidden className={`absolute left-[10%] top-[7px] h-[2px] ${c.line} ${tone === 'warn' ? 'w-[20%]' : 'w-[80%]'}`} />
          {steps.map(([label, when], i) => {
            const lit = tone === 'green' || i === 0;
            const last = i === steps.length - 1;
            return (
              <li key={i} className="relative flex flex-col items-center gap-2 text-center">
                <span className={`h-[15px] w-[15px] rounded-full border-2 ${lit ? c.dot : 'border-line bg-white'} ${last ? 'ring-4 ' + (tone === 'warn' ? 'ring-warn-soft' : 'ring-green-soft') : ''}`} />
                <span className={`px-1 text-[13.5px] font-semibold leading-tight ${lit || last ? 'text-navy' : 'text-muted'} ${last && tone === 'warn' ? '!text-warn' : ''}`}>{label}</span>
                <span className="text-[11.5px] uppercase tracking-wider text-muted">{when}</span>
              </li>
            );
          })}
        </ol>
      </div>
      <p className="max-w-[70ch] text-[15px] leading-relaxed text-muted">{text}</p>
    </div>
  );
}
