'use client';

import { useState } from 'react';
import { Icon } from './Icon';
import { APP_URL, families, leadActivity } from '@/lib/site';

// Test rapide de l'accueil : le visiteur choisit son secteur, voit le message
// de bienvenue qu'enverrait NENTIX et laisse son numéro pour le recevoir sur
// WhatsApp. La demande part dans les leads de l'application.

const LEADS_ENDPOINT = `${APP_URL}/api/public/v1/leads`;


const welcome: Record<string, string> = {
  beaute: 'Bonjour {prenom}, bienvenue au Salon Élégance ! Votre prochain rendez-vous vous sera rappelé la veille. Répondez 1 pour réserver, 2 pour parler à quelqu’un.',
  sante: 'Bonjour {prenom}, bienvenue au Cabinet Sourire. Votre rendez-vous vous sera rappelé la veille. Répondez 1 pour prendre rendez-vous, 2 pour parler au secrétariat.',
  technique: 'Bonjour {prenom}, bienvenue au Garage Akanda ! Entretien, réparation ou devis : répondez 1 pour un passage à l’atelier, 2 pour être rappelé.',
  sport: 'Bonjour {prenom}, bienvenue au Club Étoile ! Retrouvez ici vos séances et l’échéance de votre abonnement. Répondez 1 pour le planning de la semaine.',
  pro: 'Bonjour {prenom}, merci pour votre intérêt. Votre conseiller vous recontacte sous 24 h. Répondez 1 pour envoyer une pièce, 2 pour être rappelé.',
  formation: 'Bonjour {prenom}, bienvenue à l’Auto-école Horizon ! Vous recevrez ici vos leçons, vos dates d’examen et vos échéances. Répondez 1 pour réserver une leçon.',
  vehicules: 'Bonjour {prenom}, bienvenue chez Ogooué Auto ! Votre véhicule vous attend demain à 8 h. Répondez 1 pour prolonger la location, 2 pour parler à quelqu’un.',
  boutiques: 'Bonjour {prenom}, bienvenue chez Boutique Élégance ! Recevez en avant-première nos nouveautés et vos points fidélité. Répondez STOP pour ne plus rien recevoir.',
};

export function QuickTest() {
  const [slug, setSlug] = useState(families[0].slug);
  const [prenom, setPrenom] = useState('');
  const family = families.find((f) => f.slug === slug) ?? families[0];
  const preview = welcome[slug].replace('{prenom}', prenom.trim() || 'Awa');

  return (
    <div className="flex w-full max-w-5xl flex-col items-center gap-5">
      <div className="flex flex-wrap justify-center gap-2" role="tablist" aria-label="Votre secteur">
        {families.map((f) => (
          <button
            key={f.slug}
            type="button"
            role="tab"
            aria-selected={f.slug === slug}
            onClick={() => setSlug(f.slug)}
            className={`inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-3.5 py-2 text-[15px] transition ${f.slug === slug ? 'border-line bg-white font-semibold text-navy shadow-card' : 'border-line/80 text-blue-text hover:bg-white'}`}
          >
            <Icon name={f.icon} className="h-4 w-4" /> {f.name}
          </button>
        ))}
      </div>

      <form action={LEADS_ENDPOINT} method="post" className="relative w-full max-w-3xl rounded-2xl border border-line bg-white text-left shadow-card">
        <input type="hidden" name="activite" value={leadActivity(slug)} />
        <input type="hidden" name="entreprise" value="Test rapide du site" />
        <input type="hidden" name="message" value={`Test rapide depuis l’accueil (${family.name})`} />
        <div aria-hidden className="absolute -left-[9999px]"><input name="site_web" tabIndex={-1} autoComplete="off" /></div>

        <div className="flex flex-col gap-4 p-5">
          <p className="text-[14px] text-muted">Le message que vos clients recevront sur WhatsApp :</p>
          <p className="max-w-xl self-start rounded-2xl rounded-tl-sm bg-[#E7FBEF] px-4 py-3 text-[16px] leading-relaxed text-navy">{preview}</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <input name="nom" required value={prenom} onChange={(e) => setPrenom(e.target.value)} placeholder="Votre prénom" autoComplete="given-name" className="h-12 rounded-xl border border-line px-4 text-[15px] outline-none focus:border-blue focus:ring-4 focus:ring-blue/10" />
            <input name="whatsapp" type="tel" required placeholder="Votre numéro WhatsApp (+241…)" autoComplete="tel" className="h-12 rounded-xl border border-line px-4 text-[15px] outline-none focus:border-blue focus:ring-4 focus:ring-blue/10" />
          </div>
        </div>

        <div className="flex flex-col gap-3 border-t border-line px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
          <label className="flex items-start gap-2 text-[13px] text-muted">
            <input name="consentement" type="checkbox" required className="mt-0.5 h-4 w-4 accent-[#0E7BD6]" />
            J’accepte de recevoir ce message de NENTIX sur WhatsApp.
          </label>
          <button type="submit" className="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-full bg-blue px-5 text-[15px] font-semibold text-white hover:bg-blue-text">
            Recevoir sur WhatsApp <Icon name="arrow" className="h-4 w-4" />
          </button>
        </div>
      </form>

      <div className="flex flex-wrap items-center justify-center gap-2 text-[15px] text-muted">
        <span>Par exemple</span> <Icon name="arrow" className="h-4 w-4" />
        {family.trades.slice(0, 5).map((t) => (
          <span key={t} className="rounded-full bg-white px-4 py-1.5">{t}</span>
        ))}
      </div>
    </div>
  );
}
