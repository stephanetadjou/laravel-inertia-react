'use client';

import { useEffect, useMemo, useState, type ChangeEvent } from 'react';
import { links } from '@/lib/site';
import { Icon } from './Icon';

// Créateur de devis en direct, côté navigateur uniquement (sur le modèle du
// créateur de carte) : rien n'est envoyé avant le clic. Les champs simples partent
// en paramètres de l'inscription (?offre=devis), le brouillon complet est gardé
// dans le navigateur (localStorage), le logo reste local.

type Line = { label: string; qty: string; price: string };
type Fields = { entreprise: string; metier: string; whatsapp: string; client: string; client_tel: string; remise: string; validite: string; note: string };

const SAMPLE_FIELDS: Fields = {
  entreprise: 'Garage Ndong Auto',
  metier: 'Mécanique automobile',
  whatsapp: '+241 77 12 34 56',
  client: 'M. Serge Mba',
  client_tel: '',
  remise: '',
  validite: '15',
  note: 'Pièces d’origine, garantie 3 mois.',
};
const SAMPLE_LINES: Line[] = [
  { label: 'Vidange moteur + filtre à huile', qty: '1', price: '35000' },
  { label: 'Plaquettes de frein avant', qty: '1', price: '45000' },
  { label: 'Main-d’œuvre', qty: '2', price: '10000' },
];
const DEPOSITS = [0, 30, 50];
const DRAFT_KEY = 'clientlyx_devis_brouillon';

const num = (s: string) => Math.max(0, Math.round(Number(String(s).replace(/\s/g, '').replace(',', '.')) || 0));
// Espaces fines insécables à la française, identiques côté serveur et navigateur.
const fcfa = (n: number) => `${String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`;

function initials(name: string) {
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('') || 'CX';
}

export function QuoteCreator() {
  const [f, setF] = useState<Fields>(SAMPLE_FIELDS);
  const [lines, setLines] = useState<Line[]>(SAMPLE_LINES);
  const [deposit, setDeposit] = useState(30);
  const [logo, setLogo] = useState<string | null>(null);
  const [today, setToday] = useState('');
  const [preview, setPreview] = useState(false);

  useEffect(() => { setToday(new Date().toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })); }, []);
  useEffect(() => () => { if (logo) URL.revokeObjectURL(logo); }, [logo]);
  useEffect(() => {
    if (!preview) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setPreview(false); };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; };
  }, [preview]);

  const set = (k: keyof Fields) => (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });
  const setLine = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));

  const onLogo = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    setLogo(URL.createObjectURL(file));
  };

  const shown = lines.filter((l) => l.label.trim() || num(l.price) > 0);
  const subtotal = shown.reduce((s, l) => s + num(l.qty) * num(l.price), 0);
  const discount = Math.min(num(f.remise), subtotal);
  const total = subtotal - discount;
  const depositAmount = Math.round((total * deposit) / 100);

  const signupUrl = useMemo(() => {
    const p = new URLSearchParams();
    (['entreprise', 'metier', 'whatsapp', 'client', 'client_tel', 'note'] as const).forEach((k) => { if (f[k].trim()) p.set(k, f[k].trim().slice(0, 240)); });
    // Lignes compactes [désignation, quantité, prix] : le devis est recréé tel quel après l'inscription.
    p.set('lignes', JSON.stringify(shown.map((l) => [l.label.trim().slice(0, 120), num(l.qty), num(l.price)])));
    if (discount > 0) p.set('remise', String(discount));
    if (deposit > 0) p.set('acompte', String(deposit));
    p.set('validite', String(num(f.validite) || 15));
    return `${links.signupPlan('devis')}&${p.toString()}`;
  }, [f, shown, discount, deposit]);

  const go = () => {
    try {
      localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...f, lignes: shown, acompte: deposit, savedAt: new Date().toISOString() }));
    } catch { /* stockage indisponible : l'inscription reçoit quand même les champs principaux */ }
    window.location.href = signupUrl;
  };

  const input = 'h-12 w-full rounded-xl border border-line bg-white px-4 text-[16px] text-ink placeholder:text-muted/60 focus:border-green focus:outline-none focus:ring-2 focus:ring-green/20';
  const label = 'flex flex-col gap-1.5 text-[14px] font-semibold text-navy';

  // Le devis tel que le client le verra : même rendu pour l'aperçu en direct et la vue plein écran.
  const sheet = (
  <div className="flex flex-col gap-4 p-5 text-[13px]">
    <div className="flex items-start justify-between gap-3">
      <div className="flex min-w-0 items-center gap-2.5">
        <span className="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-[#E8590C] font-display text-sm font-bold text-white">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {logo ? <img src={logo} alt="" className="h-full w-full object-cover" /> : initials(f.entreprise)}
        </span>
        <div className="min-w-0 leading-tight">
          <p className="break-words font-bold text-navy">{f.entreprise || 'Votre entreprise'}</p>
          <p className="break-words text-[11px] text-muted">{f.metier}</p>
          <p className="text-[11px] text-muted">{f.whatsapp}</p>
        </div>
      </div>
      <div className="shrink-0 text-right">
        <span className="rounded-full bg-sun px-2 py-0.5 text-[10px] font-bold text-navy">DEV-0001</span>
        <p className="mt-1 text-[11px] text-muted">{today}</p>
      </div>
    </div>

    <div className="rounded-xl bg-soft p-3">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Client</p>
      <p className="break-words font-semibold text-navy">{f.client || 'Nom du client'}</p>
    </div>

    <table className="w-full table-fixed text-left">
      <thead>
        <tr className="border-b border-line text-[10.5px] uppercase tracking-wide text-muted">
          <th className="pb-1.5 font-semibold">Désignation</th>
          <th className="w-9 pb-1.5 text-center font-semibold">Qté</th>
          <th className="w-[92px] pb-1.5 text-right font-semibold">Montant</th>
        </tr>
      </thead>
      <tbody>
        {shown.map((l, i) => (
          <tr key={i} className="border-b border-line/70 align-top">
            <td className="break-words py-2 pr-2 font-medium text-ink">{l.label || '—'}<span className="block text-[10.5px] font-normal text-muted">{fcfa(num(l.price))} l’unité</span></td>
            <td className="py-2 text-center text-ink">{num(l.qty)}</td>
            <td className="whitespace-nowrap py-2 text-right font-semibold text-navy">{fcfa(num(l.qty) * num(l.price))}</td>
          </tr>
        ))}
      </tbody>
    </table>

    <div className="flex flex-col gap-1">
      <p className="flex justify-between text-muted"><span>Sous-total</span><span className="whitespace-nowrap">{fcfa(subtotal)}</span></p>
      {discount > 0 && <p className="flex justify-between text-muted"><span>Remise</span><span className="whitespace-nowrap">− {fcfa(discount)}</span></p>}
      <p className="mt-1 flex items-center justify-between border-t-2 border-navy pt-2">
        <span className="font-display text-[15px] font-bold text-navy">Total</span>
        <span className="whitespace-nowrap font-display text-[17px] font-bold text-[#E8590C]">{fcfa(total)}</span>
      </p>
      {deposit > 0 && <p className="flex justify-between font-semibold text-navy"><span>Acompte à la signature ({deposit} %)</span><span className="whitespace-nowrap">{fcfa(depositAmount)}</span></p>}
    </div>

    <p className="text-[11.5px] text-muted">Devis valable {num(f.validite) || 15} jours.{f.note.trim() && ` ${f.note.trim()}`}</p>

    <span className="flex h-11 items-center justify-center gap-2 rounded-full bg-[#E8590C] font-semibold text-white">
      <Icon name="check" className="h-4 w-4" strokeWidth={2.6} /> Accepter et signer
    </span>
    <p className="text-center text-[11px] text-muted">Créé gratuitement avec <b className="cx-brand">Cliently<span className="cx-x">X</span></b></p>
  </div>
  );

  return (
    <div className="grid items-start gap-10 lg:grid-cols-[1fr_400px] lg:gap-14">
      <form className="flex min-w-0 flex-col gap-5 rounded-3xl border border-line bg-white p-5 shadow-card sm:p-8" onSubmit={(e) => { e.preventDefault(); go(); }}>
        <div className="flex flex-col gap-1">
          <p className="font-display text-xl font-bold text-navy">Composez votre devis</p>
          <p className="text-[15px] text-muted">L’aperçu se met à jour pendant que vous tapez. Rien n’est envoyé avant l’inscription.</p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <label className={label}>Entreprise ou prestataire
            <input className={input} value={f.entreprise} onChange={set('entreprise')} autoComplete="organization" maxLength={60} />
          </label>
          <label className={label}>Métier
            <input className={input} value={f.metier} onChange={set('metier')} maxLength={60} />
          </label>
          <label className={label}>Téléphone WhatsApp
            <input className={input} value={f.whatsapp} onChange={set('whatsapp')} type="tel" inputMode="tel" autoComplete="tel" maxLength={20} />
          </label>
          <label className={label}>Nom du client
            <input className={input} value={f.client} onChange={set('client')} maxLength={60} />
          </label>
          <label className={label}>WhatsApp du client (facultatif)
            <input className={input} value={f.client_tel} onChange={set('client_tel')} type="tel" inputMode="tel" placeholder="+241 66 00 11 22" maxLength={20} />
          </label>
        </div>

        <div className="flex flex-col gap-2">
          <span className="text-[14px] font-semibold text-navy">Logo (facultatif)</span>
          <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-line bg-soft px-4 py-3 text-[15px] text-muted hover:border-green/50">
            <Icon name="upload" className="h-5 w-5 shrink-0 text-green-text" />
            {logo ? 'Changer le logo' : 'Choisir une image (aperçu sur votre appareil uniquement)'}
            <input type="file" accept="image/*" className="sr-only" onChange={onLogo} />
          </label>
        </div>

        <fieldset className="flex flex-col gap-3">
          <legend className="pb-1 text-[14px] font-semibold text-navy">Prestations</legend>
          {lines.map((l, i) => (
            <div key={i} className="grid grid-cols-[1fr_auto] gap-2 rounded-2xl bg-soft p-3 sm:grid-cols-[1fr_70px_120px_auto] sm:items-center sm:bg-transparent sm:p-0">
              <input className={`${input} col-span-2 sm:col-span-1`} value={l.label} onChange={(e) => setLine(i, { label: e.target.value })} placeholder="Désignation" aria-label={`Désignation ligne ${i + 1}`} maxLength={80} />
              <div className="col-span-2 grid grid-cols-[70px_1fr_auto] gap-2 sm:contents">
                <input className={`${input} px-3 text-center`} value={l.qty} onChange={(e) => setLine(i, { qty: e.target.value })} inputMode="decimal" placeholder="Qté" aria-label={`Quantité ligne ${i + 1}`} />
                <input className={`${input} px-3`} value={l.price} onChange={(e) => setLine(i, { price: e.target.value })} inputMode="numeric" placeholder="Prix FCFA" aria-label={`Prix unitaire ligne ${i + 1}`} />
                <button type="button" onClick={() => setLines(lines.filter((_, j) => j !== i))} disabled={lines.length === 1}
                  className="grid h-12 w-12 place-items-center rounded-xl border border-line bg-white text-muted hover:text-navy disabled:opacity-40" aria-label={`Supprimer la ligne ${i + 1}`}>
                  <Icon name="close" className="h-5 w-5" />
                </button>
              </div>
            </div>
          ))}
          <button type="button" onClick={() => setLines([...lines, { label: '', qty: '1', price: '' }])} disabled={lines.length >= 12}
            className="inline-flex h-11 w-fit items-center gap-2 rounded-full border border-line px-5 text-[15px] font-semibold text-navy hover:border-navy/30 disabled:opacity-40">
            + Ajouter une ligne
          </button>
        </fieldset>

        <div className="grid gap-4 sm:grid-cols-2">
          <label className={label}>Remise en FCFA (facultatif)
            <input className={input} value={f.remise} onChange={set('remise')} inputMode="numeric" placeholder="0" />
          </label>
          <label className={label}>Validité (jours)
            <input className={input} value={f.validite} onChange={set('validite')} inputMode="numeric" maxLength={3} />
          </label>
        </div>

        <fieldset className="flex flex-col gap-2">
          <legend className="pb-2 text-[14px] font-semibold text-navy">Acompte demandé</legend>
          <div className="flex gap-2">
            {DEPOSITS.map((d) => (
              <button key={d} type="button" onClick={() => setDeposit(d)} aria-pressed={deposit === d}
                className={`h-11 flex-1 rounded-full text-[15px] font-semibold transition ${deposit === d ? 'bg-navy text-white' : 'border border-line bg-white text-navy hover:border-navy/30'}`}>
                {d === 0 ? 'Aucun' : `${d} %`}
              </button>
            ))}
          </div>
        </fieldset>

        <label className={label}>Note (facultatif)
          <textarea className={`${input} h-24 py-3`} value={f.note} onChange={set('note')} maxLength={240} />
        </label>

        <button type="button" onClick={() => setPreview(true)} className="mt-2 inline-flex h-[52px] items-center justify-center gap-2 rounded-full border-2 border-navy bg-white px-6 text-base font-semibold text-navy transition hover:bg-soft">
          <Icon name="eye" className="h-5 w-5" /> Aperçu du devis
        </button>
        <div className="flex flex-col gap-3 sm:flex-row">
          <button type="submit" className="inline-flex h-[52px] flex-1 items-center justify-center gap-2 rounded-full bg-[#25D366] px-6 text-base font-semibold text-white transition hover:brightness-95">
            <Icon name="message" className="h-5 w-5" /> Envoyer sur WhatsApp
          </button>
          <button type="button" onClick={go} className="inline-flex h-[52px] flex-1 items-center justify-center gap-2 rounded-full bg-green px-6 text-base font-semibold text-white transition hover:bg-green-text">
            Enregistrer mon devis <Icon name="arrow" className="h-5 w-5" />
          </button>
        </div>
        <p className="text-[13px] text-muted">Gratuit : 3 devis par mois. Créez votre compte pour envoyer le lien à votre client.</p>
      </form>

      {/* Aperçu du devis ; data-no-brand : la marque y est écrite à la main. */}
      <div className="mx-auto w-full max-w-[400px] lg:sticky lg:top-28" data-no-brand aria-live="polite">
        <div className="rounded-[2.4rem] bg-navy-deep p-3 shadow-float">
          <div className="overflow-hidden rounded-[1.9rem] bg-white">
            <div className="flex justify-center pt-2"><span className="h-1.5 w-16 rounded-full bg-line" /></div>
            {sheet}
          </div>
        </div>
      </div>

      {preview && (
        <div className="fixed inset-0 z-[100] flex flex-col bg-navy-deep/80 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Aperçu du devis" data-no-brand onClick={() => setPreview(false)}>
          <div className="mx-auto flex h-full w-full max-w-[520px] flex-col bg-[#FAFAF9] sm:my-6 sm:h-[calc(100%-3rem)] sm:rounded-3xl sm:shadow-float" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
              <p className="text-[14px] font-semibold text-navy">Ce que votre client verra en ouvrant le lien</p>
              <button type="button" onClick={() => setPreview(false)} className="grid h-10 w-10 shrink-0 place-items-center rounded-full text-muted hover:bg-soft hover:text-navy" aria-label="Fermer l’aperçu">
                <Icon name="close" className="h-5 w-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-4">
              <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-card">{sheet}</div>
            </div>
            <div className="grid grid-cols-2 gap-3 border-t border-line bg-white px-4 py-3 sm:rounded-b-3xl">
              <button type="button" onClick={() => setPreview(false)} className="inline-flex h-12 items-center justify-center rounded-full border border-line px-4 text-[15px] font-semibold text-navy hover:border-navy/30">Modifier</button>
              <button type="button" onClick={go} className="inline-flex h-12 items-center justify-center gap-2 rounded-full bg-[#25D366] px-4 text-[15px] font-semibold text-white hover:brightness-95">
                <Icon name="message" className="h-5 w-5" /> Envoyer sur WhatsApp
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
