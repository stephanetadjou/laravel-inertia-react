'use client';

import { useEffect, useMemo, useState, type ChangeEvent } from 'react';
import { links } from '@/lib/site';
import { Icon } from './Icon';

// Créateur de carte digitale en direct, côté navigateur uniquement : rien n'est
// envoyé tant que le visiteur ne clique pas sur « Créer ma carte gratuite »
// (les champs partent alors en paramètres de l'inscription, la photo reste locale).

const COLORS = [
  { name: 'Orange', value: '#E8590C' },
  { name: 'Prune', value: '#2B1A3D' },
  { name: 'Bleu', value: '#1D4ED8' },
  { name: 'Vert', value: '#15803D' },
  { name: 'Bordeaux', value: '#9F1239' },
  { name: 'Noir', value: '#1C1917' },
];

type Fields = { nom: string; poste: string; entreprise: string; whatsapp: string; email: string };

// Réseaux sociaux (facultatifs) : @pseudo ou lien ; repris à l'inscription.
// Couleurs officielles des réseaux (fond, icône blanche).
const BRAND: Record<string, string> = {
  facebook: '#1877F2',
  instagram: 'linear-gradient(45deg, #F58529, #DD2A7B, #8134AF, #515BD4)',
  tiktok: '#000000',
  linkedin: '#0A66C2',
  youtube: '#FF0000',
  x: '#000000',
  whatsapp: '#25D366',
};

const SOCIALS: { key: string; label: string; path: string }[] = [
  { key: 'facebook', label: 'Facebook', path: 'M14 8h3V4h-3c-2.8 0-4 1.8-4 4.3V10H7v4h3v8h4v-8h3l1-4h-4V8.6c0-.4.3-.6.6-.6Z' },
  { key: 'instagram', label: 'Instagram', path: 'M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4Zm5 5a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm5.5-1.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z' },
  { key: 'tiktok', label: 'TikTok', path: 'M16 3c.4 2.3 1.8 3.8 4 4v3.2c-1.5 0-2.9-.5-4-1.3V15a6 6 0 1 1-6-6v3.3A2.7 2.7 0 1 0 12.7 15V3H16Z' },
  { key: 'linkedin', label: 'LinkedIn', path: 'M4 9h4v12H4V9Zm2-6a2.2 2.2 0 1 1 0 4.4A2.2 2.2 0 0 1 6 3Zm5 6h3.8v1.7c.6-1 1.9-2 3.9-2 4 0 4.3 2.6 4.3 6V21h-4v-5.6c0-1.4 0-3.1-1.9-3.1-2 0-2.2 1.5-2.2 3V21H11V9Z' },
  { key: 'youtube', label: 'YouTube', path: 'M22 8.2c-.2-1.7-1-2.8-2.8-3C16.6 5 12 5 12 5s-4.6 0-7.2.2c-1.8.2-2.6 1.3-2.8 3C1.8 9.6 1.8 12 1.8 12s0 2.4.2 3.8c.2 1.7 1 2.8 2.8 3 2.6.2 7.2.2 7.2.2s4.6 0 7.2-.2c1.8-.2 2.6-1.3 2.8-3 .2-1.4.2-3.8.2-3.8s0-2.4-.2-3.8ZM10 15.3V8.7l5.5 3.3-5.5 3.3Z' },
  { key: 'x', label: 'X', path: 'M17.8 3h3.1l-6.8 7.8L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.3-8.3L2 3h6.4l4.4 5.8L17.8 3Zm-1.1 16.2h1.7L7.4 4.7H5.6l11.1 14.5Z' },
];

function SocialGlyph({ path, className = 'h-4 w-4' }: { path: string; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden="true">
      <path d={path} fillRule="evenodd" />
    </svg>
  );
}

const EMPTY: Fields = { nom: '', poste: '', entreprise: '', whatsapp: '', email: '' };
const SAMPLE: Fields = { nom: 'Aïcha Moussavou', poste: 'Gérante', entreprise: 'Institut Éclat', whatsapp: '+241 77 12 34 56', email: 'contact@institut-eclat.ga' };

function initials(name: string) {
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('') || 'CX';
}

// QR d'exemple : trois repères et des modules tirés du texte (non scannable).
function SampleQr({ seed, color }: { seed: string; color: string }) {
  const size = 25;
  const cells = useMemo(() => {
    let h = 2166136261;
    for (const c of seed || 'clientlyx') h = Math.imul(h ^ c.charCodeAt(0), 16777619);
    const out: [number, number][] = [];
    const finder = (x: number, y: number) => (x < 8 && y < 8) || (x > size - 9 && y < 8) || (x < 8 && y > size - 9);
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        if (finder(x, y)) continue;
        h = Math.imul(h ^ (x * 31 + y), 2654435761) >>> 0;
        if (h % 100 < 48) out.push([x, y]);
      }
    }
    return out;
  }, [seed]);

  const Finder = ({ x, y }: { x: number; y: number }) => (
    <g>
      <rect x={x} y={y} width={7} height={7} fill={color} />
      <rect x={x + 1} y={y + 1} width={5} height={5} fill="#fff" />
      <rect x={x + 2} y={y + 2} width={3} height={3} fill={color} />
    </g>
  );

  return (
    <svg viewBox={`-1 -1 ${size + 2} ${size + 2}`} className="h-24 w-24 rounded-lg bg-white" role="img" aria-label="QR code d’exemple">
      {cells.map(([x, y]) => <rect key={`${x}-${y}`} x={x} y={y} width={1} height={1} fill={color} />)}
      <Finder x={0} y={0} />
      <Finder x={size - 7} y={0} />
      <Finder x={0} y={size - 7} />
    </svg>
  );
}

export function CardCreator() {
  const [f, setF] = useState<Fields>(EMPTY);
  const [color, setColor] = useState(COLORS[0].value);
  const [photo, setPhoto] = useState<string | null>(null);
  const [socials, setSocials] = useState<Record<string, string>>({});
  const filledSocials = SOCIALS.filter((s) => (socials[s.key] ?? '').trim() !== '');

  useEffect(() => () => { if (photo) URL.revokeObjectURL(photo); }, [photo]);

  const v = (k: keyof Fields) => f[k] || SAMPLE[k];
  const set = (k: keyof Fields) => (e: ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });

  const onPhoto = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    setPhoto(URL.createObjectURL(file));
  };

  const signupUrl = useMemo(() => {
    const p = new URLSearchParams();
    (Object.keys(f) as (keyof Fields)[]).forEach((k) => { if (f[k].trim()) p.set(k, f[k].trim()); });
    p.set('couleur', color);
    filledSocials.forEach((s) => p.set(s.key, socials[s.key].trim().slice(0, 120)));
    return `${links.cardSignup}&${p.toString()}`;
  }, [f, color, socials]);

  const input = 'h-12 w-full rounded-xl border border-line bg-white px-4 text-[16px] text-ink placeholder:text-muted/60 focus:border-green focus:outline-none focus:ring-2 focus:ring-green/20';

  return (
    <div className="grid items-start gap-10 lg:grid-cols-[1fr_380px] lg:gap-14">
      <form className="flex flex-col gap-5 rounded-3xl border border-line bg-white p-6 shadow-card sm:p-8" onSubmit={(e) => { e.preventDefault(); window.location.href = signupUrl; }}>
        <div className="flex flex-col gap-1">
          <p className="font-display text-xl font-bold text-navy">Composez votre carte</p>
          <p className="text-[15px] text-muted">L’aperçu se met à jour pendant que vous tapez. Rien n’est enregistré avant l’inscription.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="flex flex-col gap-1.5 text-[14px] font-semibold text-navy">Nom et prénom
            <input className={input} value={f.nom} onChange={set('nom')} placeholder={SAMPLE.nom} autoComplete="name" maxLength={60} />
          </label>
          <label className="flex flex-col gap-1.5 text-[14px] font-semibold text-navy">Poste
            <input className={input} value={f.poste} onChange={set('poste')} placeholder={SAMPLE.poste} autoComplete="organization-title" maxLength={60} />
          </label>
          <label className="flex flex-col gap-1.5 text-[14px] font-semibold text-navy">Entreprise
            <input className={input} value={f.entreprise} onChange={set('entreprise')} placeholder={SAMPLE.entreprise} autoComplete="organization" maxLength={60} />
          </label>
          <label className="flex flex-col gap-1.5 text-[14px] font-semibold text-navy">Téléphone WhatsApp
            <input className={input} value={f.whatsapp} onChange={set('whatsapp')} placeholder={SAMPLE.whatsapp} type="tel" inputMode="tel" autoComplete="tel" maxLength={20} />
          </label>
          <label className="flex flex-col gap-1.5 text-[14px] font-semibold text-navy sm:col-span-2">E-mail
            <input className={input} value={f.email} onChange={set('email')} placeholder={SAMPLE.email} type="email" autoComplete="email" maxLength={80} />
          </label>
        </div>
        <div className="flex flex-col gap-2">
          <span className="text-[14px] font-semibold text-navy">Photo ou logo</span>
          <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-line bg-soft px-4 py-3 text-[15px] text-muted hover:border-green/50">
            <Icon name="upload" className="h-5 w-5 text-green-text" />
            {photo ? 'Changer l’image' : 'Choisir une image (aperçu sur votre appareil uniquement)'}
            <input type="file" accept="image/*" className="sr-only" onChange={onPhoto} />
          </label>
        </div>
        <details className="group rounded-xl border border-line px-4 py-3">
          <summary className="flex cursor-pointer list-none items-center justify-between text-[14px] font-semibold text-navy [&::-webkit-details-marker]:hidden">
            Réseaux sociaux (facultatif){filledSocials.length > 0 && ` · ${filledSocials.length}`}
            <Icon name="chevron" className="h-5 w-5 text-muted transition group-open:rotate-180" />
          </summary>
          <p className="pt-2 text-[13px] text-muted">Tapez votre @pseudo ou collez le lien de la page.</p>
          <div className="grid gap-3 pt-3 sm:grid-cols-2">
            {SOCIALS.map((s) => (
              <label key={s.key} className="flex items-center gap-2">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full text-white" style={{ background: BRAND[s.key] ?? color }}><SocialGlyph path={s.path} /></span>
                <input className={input} value={socials[s.key] ?? ''} onChange={(e) => setSocials({ ...socials, [s.key]: e.target.value })} placeholder={`${s.label} : @pseudo`} aria-label={s.label} maxLength={120} />
              </label>
            ))}
          </div>
        </details>
        <fieldset className="flex flex-col gap-2">
          <legend className="pb-2 text-[14px] font-semibold text-navy">Couleur de la carte</legend>
          <div className="flex flex-wrap gap-3">
            {COLORS.map((c) => (
              <button key={c.value} type="button" onClick={() => setColor(c.value)} aria-label={c.name} aria-pressed={color === c.value}
                className={`h-10 w-10 rounded-full ring-offset-2 transition ${color === c.value ? 'ring-2 ring-navy' : 'ring-1 ring-line hover:scale-105'}`} style={{ background: c.value }} />
            ))}
          </div>
        </fieldset>
        <button type="submit" className="mt-2 inline-flex h-[52px] items-center justify-center gap-2 rounded-full bg-green px-7 text-base font-semibold text-white transition hover:bg-green-text">
          Créer ma carte gratuite <Icon name="arrow" className="h-5 w-5" />
        </button>
        <p className="text-[13px] text-muted">Gratuit, sans carte bancaire. Vous retrouvez ces informations à l’inscription.</p>
      </form>

      {/* Aperçu façon téléphone ; data-no-brand : la marque y est écrite à la main. */}
      <div className="mx-auto w-full max-w-[340px] lg:sticky lg:top-28" data-no-brand aria-live="polite">
        <div className="rounded-[2.6rem] bg-navy-deep p-3 shadow-float">
          <div className="overflow-hidden rounded-[2rem] bg-white">
            <div className="relative h-28" style={{ background: `linear-gradient(135deg, ${color}, ${color}cc)` }}>
              <div className="absolute left-1/2 top-2 h-1.5 w-16 -translate-x-1/2 rounded-full bg-black/20" />
            </div>
            <div className="-mt-12 flex flex-col items-center gap-1 px-5 pb-5 text-center">
              <div className="grid h-24 w-24 place-items-center overflow-hidden rounded-full border-4 border-white bg-soft font-display text-2xl font-bold text-white shadow-card" style={photo ? undefined : { background: color }}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                {photo ? <img src={photo} alt="" className="h-full w-full object-cover" /> : initials(v('nom'))}
              </div>
              <p className="mt-2 break-words font-display text-[20px] font-bold leading-tight text-navy">{v('nom')}</p>
              <p className="text-[14px] text-muted">{v('poste')}</p>
              <p className="text-[14px] font-semibold" style={{ color }}>{v('entreprise')}</p>
              <div className="mt-4 grid w-full grid-cols-2 gap-2">
                <span className="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl bg-[#25D366] text-[14px] font-semibold text-white"><Icon name="message" className="h-4 w-4" /> WhatsApp</span>
                <span className="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl text-[14px] font-semibold text-white" style={{ background: color }}><Icon name="phone" className="h-4 w-4" /> Appeler</span>
                <span className="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-line text-[13px] font-semibold text-navy"><Icon name="users" className="h-4 w-4" /> Enregistrer</span>
                <span className="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-line text-[13px] font-semibold text-navy"><Icon name="calendar" className="h-4 w-4" /> Prendre RDV</span>
              </div>
              {filledSocials.length > 0 && (
                <div className="mt-3 flex flex-wrap justify-center gap-2">
                  {filledSocials.map((s) => (
                    <span key={s.key} title={s.label} className="grid h-9 w-9 place-items-center rounded-full text-white" style={{ background: BRAND[s.key] ?? color }}><SocialGlyph path={s.path} /></span>
                  ))}
                </div>
              )}
              <div className="mt-4 w-full space-y-1.5 rounded-xl bg-soft p-3 text-left text-[13px] text-ink">
                <p className="flex items-center gap-2 break-all"><Icon name="phone" className="h-4 w-4 shrink-0 text-muted" /> {v('whatsapp')}</p>
                <p className="flex items-center gap-2 break-all"><Icon name="send" className="h-4 w-4 shrink-0 text-muted" /> {v('email')}</p>
              </div>
              <div className="mt-4 flex items-center gap-3 rounded-xl border border-line p-2 pr-3 text-left">
                <SampleQr seed={v('nom') + v('entreprise')} color="#1C1917" />
                <p className="text-[12.5px] leading-snug text-muted">Scannez pour ouvrir la carte<br /><span className="text-[11px]">(QR d’exemple)</span></p>
              </div>
              <p className="mt-4 text-[11.5px] text-muted">Créé avec <b className="cx-brand">Cliently<span className="cx-x">X</span></b></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
