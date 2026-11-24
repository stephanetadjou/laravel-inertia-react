import { Icon } from '@/components/Icon';
import { HeroChat } from '@/components/HeroChat';
import { SectorTabs } from '@/components/SectorTabs';
import { ChatbotMock, FideliteMock, RelancesMock } from '@/components/mockups';
import { CtaBand, Faq, PricingCards } from '@/components/sections';
import { Container, SectionTitle } from '@/components/ui';
import { launchFamilies, launchPitch, links } from '@/lib/site';

// Accueil du lancement (15/12/2026) : 7 blocs, un seul fil.
// Comprendre en 5 secondes → se reconnaître → être rassuré → créer son compte ou réserver une démo.
export default function Home() {
  return (
    <>
      <Hero />
      <Problems />
      <Features />
      <section id="secteurs" className="bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Votre secteur" title="Réglé pour votre métier dès l’inscription." lead="Choisissez votre secteur : vocabulaire, messages, relances et tableaux de bord arrivent prêts." />
          <SectorTabs families={launchFamilies} pitch={launchPitch} trialHref={links.trial} />
        </Container>
      </section>
      <Steps />
      <section id="tarifs" className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Tarifs" title="Commencez gratuitement. Payez en FCFA quand vous grandissez." lead="L’offre Gratuit est permanente, sans carte bancaire. Les offres payantes se règlent par Mobile Money." />
          <a href={links.trial} className="mx-auto flex w-full max-w-3xl flex-col items-center justify-between gap-4 rounded-3xl border-2 border-green bg-[#FFF6EE] p-6 text-center sm:flex-row sm:text-left">
            <span>
              <span className="block font-display text-2xl font-bold text-navy">Gratuit, pour toujours</span>
              <span className="block text-[15px] text-muted">Fiches clients, relances WhatsApp en un clic, carte digitale. Sans carte bancaire.</span>
            </span>
            <span className="inline-flex h-12 shrink-0 items-center gap-2 rounded-lg bg-green px-6 font-semibold text-white">Créer mon compte <Icon name="arrow" className="h-4 w-4" /></span>
          </a>
          <PricingCards />
          <p className="text-center text-[14px] text-muted">Les messages WhatsApp marketing sont facturés directement par Meta à votre entreprise · <a href="/tarifs" className="font-semibold text-blue-text underline-offset-4 hover:underline">Comparer les offres</a></p>
        </Container>
      </section>
      <Trust />
      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Questions fréquentes" title="Ce que les gérants nous demandent." />
          <Faq limit={5} />
        </Container>
      </section>
      <CtaBand />
    </>
  );
}

function Hero() {
  return (
    <section className="relative overflow-hidden bg-soft">
      <Container className="grid items-center gap-12 pb-16 pt-12 sm:pt-16 lg:grid-cols-[1.15fr_1fr] lg:pb-24">
        <div className="flex flex-col items-start gap-6">
          <span className="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 text-[13px] font-semibold text-navy ring-1 ring-line">
            <span className="h-2 w-2 rounded-full bg-green" /> Lancement à Libreville le 15 décembre 2026
          </span>
          <h1 className="font-display text-[2.4rem] font-bold leading-[1.05] tracking-[-0.025em] text-navy sm:text-[3.4rem] lg:text-[3.8rem]">
            Votre assistant client sur WhatsApp, <span className="text-green-text">24 h/24.</span>
          </h1>
          <p className="max-w-xl text-[1.15rem] leading-relaxed text-muted">
            Il répond aux prix, aux horaires et aux disponibilités, prend les rendez-vous et crée la fiche de chaque client. Vous, vous faites votre métier.
          </p>
          <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
            <a href={links.trial} className="inline-flex h-[54px] items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-green px-7 text-base font-semibold text-white hover:bg-green-text">Essayer gratuitement <Icon name="arrow" className="h-4 w-4" /></a>
            <a href={links.whatsapp} target="_blank" rel="noopener" data-source="site_whatsapp" className="inline-flex h-[54px] items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-line bg-white px-7 text-base font-semibold text-navy hover:border-navy/40"><Icon name="message" className="h-5 w-5 text-[#128C7E]" /> Tester sur WhatsApp</a>
          </div>
          <ul className="flex flex-wrap gap-x-6 gap-y-2 text-[14px] text-muted">
            {['Gratuit pour toujours', 'Sans carte bancaire', 'Installation faite pour vous'].map((g) => (
              <li key={g} className="inline-flex items-center gap-1.5"><Icon name="check" className="h-4 w-4 text-green-text" strokeWidth={2.5} />{g}</li>
            ))}
          </ul>
          <a href={links.demo} className="inline-flex items-center gap-1.5 text-[15px] font-semibold text-blue-text hover:underline"><Icon name="calendar" className="h-4 w-4" /> Ou réservez une démo de 30 minutes</a>
        </div>
        <HeroChat />
      </Container>
    </section>
  );
}

const problems = [
  { icon: 'phone', t: 'Le téléphone sonne toute la journée', d: '« C’est combien ? », « Vous êtes ouvert ? », « Il reste de la place samedi ? » : vous répondez au lieu de travailler.' },
  { icon: 'calendarX', t: 'Des rendez-vous oubliés', d: 'Le client oublie, prévient au dernier moment ou ne vient pas. Le créneau est perdu.' },
  { icon: 'userX', t: 'Des clients qui ne reviennent pas', d: 'Une habituée ne revient plus et personne ne s’en aperçoit avant des mois.' },
];

function Problems() {
  return (
    <section className="py-20">
      <Container className="flex flex-col gap-10">
        <SectionTitle center eyebrow="Le constat" title="Vous perdez du temps et des clients sans le voir." />
        <div className="grid gap-5 md:grid-cols-3">
          {problems.map((p) => (
            <div key={p.t} className="flex flex-col gap-3 rounded-2xl bg-soft p-7">
              <span className="grid h-12 w-12 place-items-center rounded-xl bg-warn-soft text-warn"><Icon name={p.icon} className="h-6 w-6" /></span>
              <h3 className="font-display text-xl font-bold text-navy">{p.t}</h3>
              <p className="text-[15.5px] leading-relaxed text-muted">{p.d}</p>
            </div>
          ))}
        </div>
      </Container>
    </section>
  );
}

function Features() {
  const rows = [
    {
      tag: 'Assistant IA et rendez-vous',
      title: 'Il répond et réserve à votre place, même à minuit.',
      points: ['Prix, horaires et disponibilités tirés de votre catalogue', 'Rendez-vous pris par boutons, sans double réservation', 'Vous reprenez la main quand il faut, avec tout l’historique'],
      cta: { label: 'Tester sur WhatsApp', href: links.whatsapp, external: true },
      visual: <div className="flex justify-center"><ChatbotMock /></div>,
    },
    {
      tag: 'Carte digitale gratuite',
      title: 'Votre carte de visite qui crée des clients.',
      points: ['Prête en 5 minutes, avec QR code à imprimer', 'WhatsApp, appel, rendez-vous et contact en un clic', 'Chaque contact reçu devient une fiche client'],
      cta: { label: 'Créer ma carte gratuite', href: links.cardSignup, external: false },
      visual: <CardPreview />,
    },
    {
      tag: 'Rappels et fidélité',
      title: 'Moins d’absences, des clients qui reviennent.',
      points: ['Rappel la veille avec « Je confirme » ou « Je décale »', 'Alerte quand un client dépasse son rythme habituel', 'Points et récompenses de fidélité sur chaque visite'],
      cta: { label: 'Essayer gratuitement', href: links.trial, external: false },
      visual: <div className="flex flex-col items-center gap-4"><RelancesMock /><FideliteMock /></div>,
    },
  ];
  return (
    <section id="fonctionnalites" className="border-y border-line bg-white py-20">
      <Container className="flex flex-col gap-16">
        <SectionTitle center eyebrow="Ce que fait ClientlyX" title="Trois outils, une seule fiche client." lead="Chaque message, chaque rendez-vous et chaque vente enrichit la même fiche. Vous voyez enfin qui revient et qui part." />
        {rows.map((r, i) => (
          <div key={r.tag} className="grid items-center gap-10 lg:grid-cols-2">
            <div className={`flex flex-col gap-5 ${i % 2 ? 'lg:order-2' : ''}`}>
              <p className="text-[13px] font-bold uppercase tracking-[0.14em] text-green-text">{r.tag}</p>
              <h3 className="font-display text-[1.9rem] font-bold leading-tight text-navy">{r.title}</h3>
              <ul className="flex flex-col gap-3">
                {r.points.map((p) => (
                  <li key={p} className="flex items-start gap-3 text-[16px] text-ink"><Icon name="check" className="mt-0.5 h-5 w-5 shrink-0 text-green-text" strokeWidth={2.6} /> {p}</li>
                ))}
              </ul>
              <a href={r.cta.href} {...(r.cta.external ? { target: '_blank', rel: 'noopener' } : {})} className="inline-flex h-12 w-fit items-center gap-2 rounded-lg bg-navy px-6 font-semibold text-white hover:brightness-110">{r.cta.label} <Icon name="arrow" className="h-4 w-4" /></a>
            </div>
            <div className="min-w-0">{r.visual}</div>
          </div>
        ))}
      </Container>
    </section>
  );
}

function CardPreview() {
  return (
    <div className="mx-auto flex w-full max-w-sm flex-col gap-4 rounded-3xl bg-navy p-6 text-white shadow-float">
      <div className="flex items-center gap-4">
        <span className="grid h-16 w-16 place-items-center rounded-full bg-sun font-display text-2xl font-bold text-navy">KL</span>
        <div>
          <p className="font-display text-xl font-bold">Kalia Louis</p>
          <p className="text-[14px] text-[#D3C7DF]">Gérante · Salon Kalia Beauté</p>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-2 text-[14px] font-semibold">
        {[['message', 'WhatsApp'], ['phone', 'Appeler'], ['calendar', 'Prendre RDV'], ['users', 'Enregistrer']].map(([icon, label]) => (
          <span key={label} className="inline-flex items-center justify-center gap-2 rounded-xl bg-white/10 py-3"><Icon name={icon} className="h-4 w-4 text-green" /> {label}</span>
        ))}
      </div>
      <p className="rounded-xl bg-white/[0.07] px-4 py-3 text-[13px] text-[#E2D8EC]">Nouveau contact : Aïcha N. · fiche créée, tâche « à rappeler » ajoutée</p>
    </div>
  );
}

function Steps() {
  const steps = [
    { n: '1', t: 'Je crée mon compte', d: 'En 2 minutes, gratuitement, en choisissant mon secteur.' },
    { n: '2', t: 'ClientlyX installe mon WhatsApp', d: 'Notre équipe connecte l’API officielle, importe mes clients et règle l’assistant avec moi.' },
    { n: '3', t: 'Mes clients sont servis', d: 'L’assistant répond, réserve et relance. Je suis tout depuis mon téléphone.' },
  ];
  return (
    <section className="py-20">
      <Container className="flex flex-col gap-10">
        <SectionTitle center eyebrow="Comment ça marche" title="Opérationnel en 3 étapes." />
        <ol className="grid gap-5 md:grid-cols-3">
          {steps.map((s) => (
            <li key={s.n} className="flex flex-col gap-3 rounded-2xl border border-line bg-white p-7">
              <span className="grid h-12 w-12 place-items-center rounded-full bg-green font-display text-xl font-bold text-white">{s.n}</span>
              <h3 className="font-display text-xl font-bold text-navy">{s.t}</h3>
              <p className="text-[15.5px] leading-relaxed text-muted">{s.d}</p>
            </li>
          ))}
        </ol>
        <div className="flex flex-col justify-center gap-3 sm:flex-row">
          <a href={links.trial} className="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-green px-7 font-semibold text-white hover:brightness-110">Essayer gratuitement</a>
          <a href={links.demo} className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-line px-7 font-semibold text-navy hover:border-navy/40"><Icon name="calendar" className="h-4 w-4" /> Réserver une démo</a>
        </div>
      </Container>
    </section>
  );
}

function Trust() {
  const items = [
    { icon: 'shield', t: 'API officielle WhatsApp de Meta', d: 'Pas de bricolage ni de risque de blocage : la connexion officielle, au nom de votre entreprise.' },
    { icon: 'wrench', t: 'Installation faite pour vous', d: 'Nous connectons votre WhatsApp, importons vos clients et réglons l’assistant avec vous.' },
    { icon: 'mapPin', t: 'Une équipe basée à Libreville', d: 'Un accompagnement en français, sur WhatsApp ou sur place.' },
    { icon: 'wallet', t: 'Paiement Mobile Money', d: 'Des prix en FCFA, sans carte bancaire, et une offre Gratuit permanente.' },
  ];
  return (
    <section className="bg-navy py-20">
      <Container className="flex flex-col gap-10">
        <SectionTitle center light eyebrow="Pourquoi nous faire confiance" title="Officiel, local et accompagné." />
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {items.map((i) => (
            <div key={i.t} className="flex flex-col gap-3 rounded-2xl bg-white/[0.06] p-6 ring-1 ring-white/10">
              <span className="grid h-11 w-11 place-items-center rounded-xl bg-green text-white"><Icon name={i.icon} className="h-5 w-5" /></span>
              <h3 className="font-display text-lg font-bold text-white">{i.t}</h3>
              <p className="text-[15px] leading-relaxed text-[#D3C7DF]">{i.d}</p>
            </div>
          ))}
        </div>
      </Container>
    </section>
  );
}
