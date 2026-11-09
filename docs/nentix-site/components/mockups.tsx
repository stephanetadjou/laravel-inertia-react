import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { LogoMark } from './ui';

// Maquettes de l'application en HTML/CSS, aux couleurs bleu-vert.
// Toutes les données sont des exemples fictifs.

const menu = [
  { icon: 'sun', label: 'Accueil' },
  { icon: 'check', label: 'À faire' },
  { icon: 'calendar', label: 'Agenda' },
  { icon: 'users', label: 'Clients' },
  { icon: 'wallet', label: 'Ventes' },
  { icon: 'message', label: 'Campagnes' },
];

export function AppWindow({ active, title, children, className = '' }: { active: string; title: string; children: ReactNode; className?: string }) {
  return (
    <div className={`overflow-hidden rounded-2xl border border-line bg-white shadow-float ${className}`}>
      <div className="flex h-9 items-center gap-1.5 border-b border-line bg-soft px-4">
        <span className="h-2.5 w-2.5 rounded-full bg-[#F87171]" />
        <span className="h-2.5 w-2.5 rounded-full bg-[#FBBF24]" />
        <span className="h-2.5 w-2.5 rounded-full bg-[#34D399]" />
        <span className="ml-3 truncate rounded-md bg-white px-3 py-0.5 text-[11px] text-muted">app.menentia.com/{title}</span>
      </div>
      <div className="flex">
        <aside className="hidden w-40 shrink-0 flex-col gap-0.5 bg-navy p-3 text-[12px] text-[#B7C8D9] sm:flex">
          <div className="mb-3 flex items-center gap-2 px-2 pt-1">
            <LogoMark className="h-4 w-5" id={`lg-app-${title}`} />
            <span className="font-display text-[13px] font-bold text-white">NENTIX</span>
          </div>
          {menu.map((m) => (
            <span key={m.label} className={`flex items-center gap-2 rounded-lg px-2 py-1.5 ${m.label === active ? 'bg-white/10 font-semibold text-white' : ''}`}>
              <Icon name={m.icon} className="h-3.5 w-3.5" /> {m.label}
            </span>
          ))}
        </aside>
        <div className="min-w-0 flex-1">{children}</div>
      </div>
    </div>
  );
}

const days = ['Lun 3', 'Mar 4', 'Mer 5', 'Jeu 6', 'Ven 7', 'Sam 8'];
type Appt = { d: number; start: number; len: number; who: string; what: string; tone: 'blue' | 'green' | 'navy' | 'amber' };
const appts: Appt[] = [
  { d: 0, start: 0, len: 2, who: 'Aïcha N.', what: 'Tresses', tone: 'blue' },
  { d: 0, start: 3, len: 1, who: 'Prisca M.', what: 'Manucure', tone: 'green' },
  { d: 1, start: 1, len: 1, who: 'Mireille O.', what: 'Brushing', tone: 'green' },
  { d: 1, start: 4, len: 2, who: 'Carine B.', what: 'Coloration', tone: 'navy' },
  { d: 2, start: 0, len: 1, who: 'Sandra K.', what: 'Soin visage', tone: 'amber' },
  { d: 2, start: 2, len: 2, who: 'Ruth A.', what: 'Tissage', tone: 'blue' },
  { d: 3, start: 1, len: 1, who: 'Linda E.', what: 'Pédicure', tone: 'green' },
  { d: 3, start: 3, len: 2, who: 'Grâce M.', what: 'Tresses', tone: 'blue' },
  { d: 4, start: 0, len: 2, who: 'Nadège T.', what: 'Coloration', tone: 'navy' },
  { d: 4, start: 3, len: 1, who: 'Estelle I.', what: 'Brushing', tone: 'green' },
  { d: 5, start: 0, len: 1, who: 'Joëlle P.', what: 'Manucure', tone: 'green' },
  { d: 5, start: 1, len: 2, who: 'Danielle O.', what: 'Tresses', tone: 'blue' },
  { d: 5, start: 4, len: 2, who: 'Murielle N.', what: 'Tissage', tone: 'blue' },
];
const tones = {
  blue: 'bg-blue-soft border-blue text-blue-text',
  green: 'bg-green-soft border-green text-green-text',
  navy: 'bg-[#E6EBF1] border-navy text-navy',
  amber: 'bg-[#FFF4DF] border-[#F59E0B] text-[#8A5300]',
};
const hours = ['9 h', '10 h', '11 h', '14 h', '15 h', '16 h'];

export function AgendaMock() {
  return (
    <AppWindow active="Agenda" title="agenda">
      <div className="flex items-center justify-between border-b border-line px-4 py-3">
        <div>
          <p className="font-display text-[15px] font-bold text-navy">Agenda · semaine du 3 novembre</p>
          <p className="text-[11px] text-muted">Salon Élégance · 3 intervenantes</p>
        </div>
        <div className="flex rounded-lg border border-line p-0.5 text-[11px] font-semibold text-muted">
          <span className="rounded-md px-2 py-1">Jour</span>
          <span className="rounded-md bg-navy px-2 py-1 text-white">Semaine</span>
          <span className="rounded-md px-2 py-1">Mois</span>
        </div>
      </div>
      <div className="grid grid-cols-[34px_repeat(6,minmax(0,1fr))] gap-px bg-line/60 p-px text-[10px]">
        <div className="bg-white" />
        {days.map((d) => (
          <div key={d} className="bg-white py-1.5 text-center font-semibold text-navy">{d}</div>
        ))}
        {hours.map((h, row) => (
          <Row key={h} h={h} row={row} />
        ))}
      </div>
    </AppWindow>
  );
}

function Row({ h, row }: { h: string; row: number }) {
  return (
    <>
      <div className="bg-white pr-1 pt-1 text-right text-muted">{h}</div>
      {days.map((_, d) => {
        const a = appts.find((x) => x.d === d && x.start === row);
        const covered = appts.some((x) => x.d === d && row > x.start && row < x.start + x.len);
        return (
          <div key={d} className="relative h-11 bg-white">
            {a && (
              <div className={`absolute inset-x-0.5 top-0.5 z-10 overflow-hidden rounded-md border-l-[3px] px-1.5 py-1 ${tones[a.tone]}`} style={{ height: `calc(${a.len * 100}% + ${(a.len - 1) * 1}px - 4px)` }}>
                <p className="truncate font-semibold">{a.who}</p>
                <p className="truncate opacity-80">{a.what}</p>
              </div>
            )}
            {!a && !covered && row === 2 && d === 1 && <div className="absolute inset-0.5 rounded-md border border-dashed border-green/70 bg-green-soft/40" />}
          </div>
        );
      })}
    </>
  );
}

export function Phone({ children, className = '', dark = false }: { children: ReactNode; className?: string; dark?: boolean }) {
  return (
    <div className={`w-[260px] rounded-[2.2rem] border-[7px] bg-navy shadow-float ${dark ? 'border-[#1E4A72] ring-1 ring-white/10' : 'border-navy'} ${className}`}>
      <div className="relative overflow-hidden rounded-[1.7rem] bg-white">
        <div className="absolute left-1/2 top-1.5 z-20 h-4 w-20 -translate-x-1/2 rounded-full bg-navy" />
        {children}
      </div>
    </div>
  );
}

export function WhatsAppMock() {
  return (
    <Phone>
      <div className="bg-[#0B5C4B] px-4 pb-2.5 pt-8 text-white">
        <div className="flex items-center gap-2.5">
          <span className="grid h-8 w-8 place-items-center rounded-full bg-white">
            <LogoMark className="h-3.5 w-4" id="lg-wa" />
          </span>
          <div>
            <p className="text-[13px] font-semibold leading-tight">Salon Élégance</p>
            <p className="text-[10px] opacity-80">Compte professionnel</p>
          </div>
        </div>
      </div>
      <div className="flex min-h-[330px] flex-col gap-2 bg-[#ECE5DD] px-3 py-3 text-[12px] leading-snug text-[#111B21]">
        <span className="mx-auto rounded-md bg-white/80 px-2 py-0.5 text-[10px] text-[#54656F]">AUJOURD’HUI</span>
        <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          Bonjour Mireille, petit rappel : votre rendez-vous <b>Tresses</b> est demain <b>mardi à 10 h</b> avec Carine.
          <br />
          <br />
          Répondez <b>1</b> pour confirmer ou <b>2</b> pour annuler.
          <p className="mt-1 text-right text-[10px] text-[#667781]">18:02</p>
        </div>
        <div className="ml-auto max-w-[60%] rounded-lg rounded-tr-none bg-[#D9FDD3] p-2.5 shadow-sm">
          1
          <p className="mt-1 text-right text-[10px] text-[#667781]">18:05 ✓✓</p>
        </div>
        <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          Merci Mireille, c’est confirmé. À demain !
          <p className="mt-1 text-right text-[10px] text-[#667781]">18:05</p>
        </div>
      </div>
    </Phone>
  );
}

// Campagne WhatsApp envoyée à un segment (commerce et distribution).
export function CampagneMock() {
  return (
    <Phone>
      <div className="bg-[#0B5C4B] px-4 pb-2.5 pt-8 text-white">
        <div className="flex items-center gap-2.5">
          <span className="grid h-8 w-8 place-items-center rounded-full bg-white">
            <LogoMark className="h-3.5 w-4" id="lg-wa-co" />
          </span>
          <div>
            <p className="text-[13px] font-semibold leading-tight">Boutique Akanda</p>
            <p className="text-[10px] opacity-80">Compte professionnel</p>
          </div>
        </div>
      </div>
      <div className="flex min-h-[330px] flex-col gap-2 bg-[#ECE5DD] px-3 py-3 text-[12px] leading-snug text-[#111B21]">
        <span className="mx-auto rounded-md bg-white/80 px-2 py-0.5 text-[10px] text-[#54656F]">AUJOURD’HUI</span>
        <div className="max-w-[90%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          Bonjour Mme Ndong, cela fait un moment ! Pour votre retour, <b>-15 %</b> sur vos produits d’entretien habituels jusqu’à samedi.
          <br />
          <br />
          Répondez <b>STOP</b> pour ne plus recevoir nos offres.
          <p className="mt-1 text-right text-[10px] text-[#667781]">10:12</p>
        </div>
        <div className="ml-auto max-w-[70%] rounded-lg rounded-tr-none bg-[#D9FDD3] p-2.5 shadow-sm">
          Merci, je passe jeudi 🙏
          <p className="mt-1 text-right text-[10px] text-[#667781]">10:40 ✓✓</p>
        </div>
        <span className="mx-auto mt-1 rounded-md bg-white/80 px-2 py-0.5 text-[10px] text-[#54656F]">Campagne « À risque » · 180 destinataires</span>
      </div>
    </Phone>
  );
}

export function ClientMock() {
  return (
    <AppWindow active="Clients" title="clients/mireille-o">
      <div className="flex flex-col gap-3 p-4">
        <div className="flex items-center gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue to-green font-display text-[15px] font-bold text-white">MO</span>
          <div className="min-w-0">
            <p className="truncate font-display text-[15px] font-bold text-navy">Mireille Obiang</p>
            <p className="truncate text-[11px] text-muted">+241 06 •• •• 42 · cliente depuis 2023</p>
          </div>
          <span className="ml-auto shrink-0 rounded-full bg-warn-soft px-2 py-0.5 text-[10px] font-bold text-warn">À risque</span>
        </div>
        <div className="grid grid-cols-[88px_repeat(3,minmax(0,1fr))] items-stretch gap-2 text-center">
          <div className="flex flex-col items-center justify-center rounded-xl bg-soft py-1">
            <ScoreRing value={72} />
            <p className="text-[10px] text-muted">Score client</p>
          </div>
          <Kpi label="Visites" value="14" />
          <Kpi label="FCFA dépensés" value="186 k" />
          <Kpi label="Rythme habituel" value="5 sem." />
        </div>
        <div className="flex items-center gap-3 rounded-xl border border-warn/30 bg-warn-soft p-3 text-[12px] text-[#7C2D12]">
          <div className="min-w-0 flex-1">
            <p className="font-semibold">Dernière visite il y a 44 jours</p>
            <p>Elle revient d’habitude toutes les 5 semaines : 9 jours de retard.</p>
          </div>
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#0B5C4B] px-3 py-1.5 text-[11px] font-semibold text-white">
            <Icon name="message" className="h-3 w-3" /> Relancer
          </span>
        </div>
        <div className="flex flex-col gap-1.5 text-[11px]">
          {[
            ['22 sept.', 'Tresses', '15 000'],
            ['15 août', 'Tresses + soin', '18 500'],
            ['10 juil.', 'Tresses', '15 000'],
          ].map(([d, sv, p]) => (
            <div key={d} className="grid grid-cols-[70px_1fr_auto] items-center rounded-lg bg-soft px-2.5 py-1.5">
              <span className="text-muted">{d}</span>
              <span className="font-medium text-navy">{sv}</span>
              <span className="tabular-nums text-muted">{p} F</span>
            </div>
          ))}
        </div>
      </div>
    </AppWindow>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col justify-center rounded-xl bg-soft px-2 py-2">
      <p className="whitespace-nowrap font-display text-[18px] font-bold tabular-nums text-navy">{value}</p>
      <p className="text-[10px] leading-tight text-muted">{label}</p>
    </div>
  );
}

function ScoreRing({ value }: { value: number }) {
  const c = 2 * Math.PI * 30;
  return (
    <svg viewBox="0 0 80 80" className="h-14 w-14" role="img" aria-label={`Score ${value} sur 100`}>
      <defs>
        <linearGradient id="ring" x1="0" x2="1">
          <stop offset="0" stopColor="#0E7BD6" />
          <stop offset="1" stopColor="#00C896" />
        </linearGradient>
      </defs>
      <circle cx="40" cy="40" r="30" fill="none" stroke="#DCE6EF" strokeWidth="8" />
      <circle cx="40" cy="40" r="30" fill="none" stroke="url(#ring)" strokeWidth="8" strokeLinecap="round" strokeDasharray={`${(c * value) / 100} ${c}`} transform="rotate(-90 40 40)" />
      <text x="40" y="46" textAnchor="middle" fontSize="18" fontWeight="700" fill="#0B2D4A">{value}</text>
    </svg>
  );
}

export function RelancesMock() {
  const groups = [
    { title: 'RDV à rappeler', count: 6, tone: 'bg-blue-soft text-blue-text', rows: [['Aïcha N.', 'Tresses · demain 9 h'], ['Prisca M.', 'Manucure · demain 14 h']] },
    { title: 'Absents à reprogrammer', count: 2, tone: 'bg-[#FFF4DF] text-[#8A5300]', rows: [['Sandra K.', 'Soin visage · absente hier']] },
    { title: 'Clients à risque', count: 11, tone: 'bg-warn-soft text-warn', rows: [['Mireille O.', '9 jours de retard'], ['Carine B.', '3 semaines de retard']] },
  ];
  return (
    <AppWindow active="À faire" title="a-faire">
      <div className="p-4">
        <p className="font-display text-[15px] font-bold text-navy">À faire aujourd’hui</p>
        <p className="mb-3 text-[11px] text-muted">19 clients à contacter · message prêt pour chacun</p>
        <div className="flex flex-col gap-3">
          {groups.map((g) => (
            <div key={g.title} className="rounded-xl border border-line">
              <div className="flex items-center justify-between border-b border-line px-3 py-2">
                <span className="text-[12px] font-semibold text-navy">{g.title}</span>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${g.tone}`}>{g.count}</span>
              </div>
              {g.rows.map(([n, d]) => (
                <div key={n} className="flex items-center gap-2 px-3 py-2 text-[11px]">
                  <span className="font-medium text-navy">{n}</span>
                  <span className="truncate text-muted">{d}</span>
                  <span className="ml-auto inline-flex shrink-0 items-center gap-1 rounded-full bg-[#0B5C4B] px-2.5 py-1 text-[10px] font-semibold text-white">
                    <Icon name="message" className="h-3 w-3" /> WhatsApp
                  </span>
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>
    </AppWindow>
  );
}

const bars = [42, 55, 48, 61, 58, 74, 69, 83];
export function DashboardMock() {
  return (
    <AppWindow active="Accueil" title="accueil">
      <div className="p-4">
        <p className="font-display text-[15px] font-bold text-navy">Bonjour Carine</p>
        <p className="mb-3 text-[11px] text-muted">Octobre 2026 · Salon Élégance</p>
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <Tile label="CA (FCFA)" value="2,4 M" delta="+12 %" />
          <Tile label="Rendez-vous" value="318" delta="+8 %" />
          <Tile label="Taux d’absence" value="4 %" delta="−3 pts" />
          <Tile label="Clients à risque" value="11" delta="à relancer" warn />
        </div>
        <div className="mt-3 rounded-xl border border-line p-3">
          <p className="mb-2 text-[11px] font-semibold text-navy">Chiffre d’affaires par semaine</p>
          <div className="flex h-24 items-end gap-2">
            {bars.map((b, i) => (
              <div key={i} className="flex-1 rounded-t-md bg-gradient-to-t from-blue to-green" style={{ height: `${b}%`, opacity: i === bars.length - 1 ? 1 : 0.55 }} />
            ))}
          </div>
        </div>
      </div>
    </AppWindow>
  );
}

function Tile({ label, value, delta, warn }: { label: string; value: string; delta: string; warn?: boolean }) {
  return (
    <div className="rounded-xl bg-soft p-2.5">
      <p className="truncate text-[10px] text-muted">{label}</p>
      <p className="whitespace-nowrap font-display text-[17px] font-bold tabular-nums text-navy">{value}</p>
      <p className={`text-[10px] font-semibold ${warn ? 'text-warn' : 'text-green-text'}`}>{delta}</p>
    </div>
  );
}

export function BookingMock() {
  const slots = ['9:00', '10:30', '11:00', '14:00', '15:30', '16:00'];
  return (
    <Phone>
      <div className="px-4 pb-5 pt-9">
        <p className="text-[10px] font-bold uppercase tracking-widest text-blue-text">Réserver</p>
        <p className="font-display text-[16px] font-bold text-navy">Salon Élégance</p>
        <p className="mb-3 flex items-center gap-1 text-[11px] text-muted"><Icon name="mapPin" className="h-3 w-3" /> Louis, Libreville</p>
        <div className="mb-3 rounded-xl border border-blue bg-blue-soft/60 p-2.5">
          <p className="text-[12px] font-semibold text-navy">Tresses africaines</p>
          <p className="text-[11px] text-muted">2 h · 15 000 FCFA · avec Carine</p>
        </div>
        <div className="mb-2 flex gap-1.5 text-center text-[10px]">
          {['Lun 3', 'Mar 4', 'Mer 5', 'Jeu 6'].map((d, i) => (
            <span key={d} className={`flex-1 rounded-lg py-1.5 font-semibold ${i === 1 ? 'bg-navy text-white' : 'bg-soft text-navy'}`}>{d}</span>
          ))}
        </div>
        <div className="grid grid-cols-3 gap-1.5">
          {slots.map((s, i) => (
            <span key={s} className={`rounded-lg border py-1.5 text-center text-[11px] font-semibold ${i === 1 ? 'border-green bg-green-soft text-green-text' : 'border-line text-navy'}`}>{s}</span>
          ))}
        </div>
        <span className="mt-4 block rounded-full bg-gradient-to-r from-blue to-green py-2.5 text-center text-[12px] font-bold text-white">Confirmer mardi 10:30</span>
        <p className="mt-2 text-center text-[10px] text-muted">Sans compte · récapitulatif sur WhatsApp</p>
      </div>
    </Phone>
  );
}

const segments = [
  { n: 'Champions', c: 38, tone: 'bg-green text-white' },
  { n: 'Fidèles', c: 74, tone: 'bg-green-soft text-green-text' },
  { n: 'Prometteurs', c: 41, tone: 'bg-blue-soft text-blue-text' },
  { n: 'Nouveaux', c: 29, tone: 'bg-blue-soft text-blue-text' },
  { n: 'À surveiller', c: 33, tone: 'bg-[#FFF4DF] text-[#8A5300]' },
  { n: 'À risque', c: 22, tone: 'bg-warn-soft text-warn' },
  { n: 'À réactiver', c: 17, tone: 'bg-warn-soft text-warn' },
  { n: 'Perdus', c: 12, tone: 'bg-[#E6EBF1] text-navy' },
];

export function SegmentsMock() {
  return (
    <AppWindow active="Clients" title="clients/segments">
      <div className="p-4">
        <p className="font-display text-[15px] font-bold text-navy">Vos 266 clients en 8 segments</p>
        <p className="mb-3 text-[11px] text-muted">Récence, fréquence et montant, recalculés chaque nuit</p>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {segments.map((s) => (
            <div key={s.n} className={`rounded-xl p-2.5 ${s.tone}`}>
              <p className="font-display text-[18px] font-bold tabular-nums">{s.c}</p>
              <p className="text-[11px] font-semibold">{s.n}</p>
            </div>
          ))}
        </div>
      </div>
    </AppWindow>
  );
}

export function RapportMock({ dark = false }: { dark?: boolean }) {
  return (
    <Phone dark={dark}>
      <div className="px-4 pb-5 pt-9 text-[11px]">
        <p className="text-[10px] text-muted">De : NENTIX · 21:00</p>
        <p className="mb-3 font-display text-[15px] font-bold text-navy">Votre rapport du mercredi 5 novembre</p>
        <p className="mb-1 text-[10px] font-bold uppercase tracking-widest text-blue-text">Aujourd’hui</p>
        <div className="mb-3 grid grid-cols-2 gap-1.5">
          <Mini label="Encaissé" value="186 500 F" />
          <Mini label="RDV honorés" value="14 / 15" />
          <Mini label="Nouveaux clients" value="3" />
          <Mini label="Revenus après relance" value="2" />
        </div>
        <p className="mb-1 text-[10px] font-bold uppercase tracking-widest text-blue-text">Demain</p>
        <ul className="flex flex-col gap-1.5 text-navy">
          <li className="flex items-center gap-2 rounded-lg bg-soft px-2 py-1.5"><Icon name="calendar" className="h-3.5 w-3.5 text-blue-text" /> 16 rendez-vous, 2 créneaux libres à 15 h</li>
          <li className="flex items-center gap-2 rounded-lg bg-soft px-2 py-1.5"><Icon name="phone" className="h-3.5 w-3.5 text-blue-text" /> 6 rendez-vous à confirmer</li>
          <li className="flex items-center gap-2 rounded-lg bg-warn-soft px-2 py-1.5 text-[#7C2D12]"><Icon name="userX" className="h-3.5 w-3.5" /> 4 clientes à risque à relancer</li>
        </ul>
      </div>
    </Phone>
  );
}

function Mini({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-soft px-2 py-1.5">
      <p className="font-display text-[13px] font-bold tabular-nums text-navy">{value}</p>
      <p className="text-[9.5px] text-muted">{label}</p>
    </div>
  );
}

export function FideliteMock() {
  const tiers = ['Découverte', 'Argent', 'Or', 'Premium'];
  return (
    <div className="w-full max-w-sm rounded-2xl bg-gradient-to-br from-navy to-[#0E4E86] p-5 text-white shadow-float">
      <div className="flex items-center justify-between">
        <p className="text-[11px] font-bold uppercase tracking-widest text-green">Carte fidélité</p>
        <LogoMark className="h-4 w-5" id="lg-fid" />
      </div>
      <p className="mt-3 font-display text-[20px] font-bold">Mireille Obiang</p>
      <p className="text-[12px] text-[#C9D9E8]">Palier Or · 1 240 points</p>
      <div className="mt-4 flex gap-1">
        {tiers.map((t, i) => (
          <div key={t} className="flex-1">
            <div className={`h-1.5 rounded-full ${i <= 2 ? 'bg-green' : 'bg-white/20'}`} />
            <p className={`mt-1 text-[10px] ${i === 2 ? 'font-bold text-white' : 'text-[#9FB4C8]'}`}>{t}</p>
          </div>
        ))}
      </div>
      <div className="mt-4 rounded-xl bg-white/10 p-3 text-[12px]">
        Récompense disponible : <b>soin offert</b> (1 000 points). Votre palier Or est conservé.
      </div>
    </div>
  );
}

// Une fenêtre de l'application avec un téléphone posé en bas à droite.
export function WithPhone({ main, phone }: { main: ReactNode; phone: ReactNode }) {
  return (
    <div className="relative sm:pb-24">
      <div className="sm:mr-16">{main}</div>
      <div className="absolute -bottom-2 right-0 z-20 hidden origin-bottom-right scale-[.72] sm:block">{phone}</div>
    </div>
  );
}

export function ChatbotMock() {
  return (
    <Phone>
      <div className="bg-[#0B5C4B] px-4 pb-2.5 pt-8 text-white">
        <div className="flex items-center gap-2.5">
          <span className="grid h-8 w-8 place-items-center rounded-full bg-white">
            <LogoMark className="h-3.5 w-4" id="lg-bot" />
          </span>
          <div>
            <p className="text-[13px] font-semibold leading-tight">Salon Élégance</p>
            <p className="text-[10px] opacity-80">Assistant NENTIX · répond 24 h/24</p>
          </div>
        </div>
      </div>
      <div className="flex min-h-[360px] flex-col gap-2 bg-[#ECE5DD] px-3 py-3 text-[12px] leading-snug text-[#111B21]">
        <div className="ml-auto max-w-[80%] rounded-lg rounded-tr-none bg-[#D9FDD3] p-2.5 shadow-sm">
          Bonsoir, vous avez une place samedi pour des tresses ?
          <p className="mt-1 text-right text-[10px] text-[#667781]">22:14 ✓✓</p>
        </div>
        <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          Bonsoir ! Samedi 8, Carine est libre à <b>9 h</b> ou à <b>14 h</b>. Les tresses durent environ 2 h, à partir de 15 000 FCFA. Quel horaire vous convient ?
          <p className="mt-1 text-right text-[10px] text-[#667781]">22:14</p>
        </div>
        <div className="ml-auto max-w-[60%] rounded-lg rounded-tr-none bg-[#D9FDD3] p-2.5 shadow-sm">
          14 h svp
          <p className="mt-1 text-right text-[10px] text-[#667781]">22:15 ✓✓</p>
        </div>
        <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          C’est réservé : <b>samedi 8 à 14 h</b> avec Carine. Vous recevrez un rappel la veille.
          <p className="mt-1 text-right text-[10px] text-[#667781]">22:15</p>
        </div>
        <span className="mx-auto mt-1 inline-flex items-center gap-1 rounded-full bg-white/90 px-2.5 py-1 text-[10px] font-semibold text-[#0B5C4B]">
          <Icon name="calendar" className="h-3 w-3" /> Rendez-vous ajouté à l’agenda
        </span>
      </div>
    </Phone>
  );
}

// Accueil : l'analyse client qui débouche sur la relance (slogan du 02/11/2026).
const heroSegments = [
  { n: 'Champions', c: 42, tone: 'bg-green text-white' },
  { n: 'Fidèles', c: 118, tone: 'bg-green-soft text-green-text' },
  { n: 'À risque', c: 27, tone: 'bg-warn-soft text-warn' },
  { n: 'Perdus', c: 15, tone: 'bg-[#E6EBF1] text-navy' },
];
const heroRows: [name: string, seg: string, tone: string, last: string, spent: string][] = [
  ['Mireille O.', 'À risque', 'bg-warn-soft text-warn', 'il y a 6 semaines', '186 000 F'],
  ['Carine B.', 'À risque', 'bg-warn-soft text-warn', 'il y a 5 semaines', '142 500 F'],
  ['Joëlle P.', 'Champion', 'bg-green text-white', 'il y a 4 jours', '312 000 F'],
  ['Sandra K.', 'Perdue', 'bg-[#E6EBF1] text-navy', 'il y a 4 mois', '95 000 F'],
  ['Ruth A.', 'Fidèle', 'bg-green-soft text-green-text', 'il y a 9 jours', '128 000 F'],
];
const heroWhy: Record<string, string> = {
  'Mireille O.': 'ne revient plus',
  'Carine B.': 'ne revient plus',
  'Joëlle P.': 'à récompenser',
  'Sandra K.': 'à reconquérir',
  'Ruth A.': 'anniversaire',
};

export function ClientsRelanceMock() {
  return (
    <AppWindow active="Clients" title="clients">
      <div className="p-4 sm:p-5 md:pr-32 lg:pr-28">
        <div className="flex flex-wrap items-end justify-between gap-2">
          <div>
            <p className="font-display text-[15px] font-bold text-navy">Vos clients · 266 fiches</p>
            <p className="text-[11px] text-muted">Segments recalculés chaque nuit selon la récence, la fréquence et le montant</p>
          </div>
          <span className="rounded-full bg-blue-soft px-2.5 py-1 text-[10px] font-semibold text-blue-text">Score moyen 68/100</span>
        </div>
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
          {heroSegments.map((s) => (
            <div key={s.n} className={`rounded-xl p-2.5 text-left ${s.tone}`}>
              <p className="font-display text-[20px] font-bold tabular-nums">{s.c}</p>
              <p className="text-[11px] font-semibold">{s.n}</p>
            </div>
          ))}
        </div>
        <div className="mt-4 rounded-xl border border-line text-left">
          <div className="flex items-center justify-between border-b border-line px-3 py-2">
            <span className="text-[12px] font-semibold text-navy">À relancer aujourd’hui</span>
            <span className="rounded-full bg-green-soft px-2 py-0.5 text-[10px] font-bold text-green-text">12 · message prêt</span>
          </div>
          <div className="hidden grid-cols-[1.2fr_.9fr_1fr_.9fr_auto] gap-2 border-b border-line px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted sm:grid">
            <span>Client</span><span>Segment</span><span>Dernière visite</span><span>Dépensé</span><span />
          </div>
          {heroRows.map(([n, seg, tone, last, spent]) => (
            <div key={n} className="grid grid-cols-[1fr_auto] items-center gap-2 border-b border-line/60 px-3 py-2 text-[11px] last:border-0 sm:grid-cols-[1.2fr_.9fr_1fr_.9fr_auto]">
              <span className="min-w-0">
                <span className="block font-medium text-navy">{n}</span>
                <span className="block truncate text-[10px] text-muted">{heroWhy[n]}</span>
              </span>
              <span className="hidden sm:block"><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${tone}`}>{seg}</span></span>
              <span className="hidden text-muted sm:block">{last}</span>
              <span className="hidden tabular-nums text-navy sm:block">{spent}</span>
              <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#0B5C4B] px-2.5 py-1 text-[10px] font-semibold text-white">
                <Icon name="message" className="h-3 w-3" /> WhatsApp
              </span>
            </div>
          ))}
        </div>
      </div>
    </AppWindow>
  );
}

export function RelanceWhatsAppMock() {
  return (
    <Phone>
      <div className="bg-[#0B5C4B] px-4 pb-2.5 pt-8 text-white">
        <div className="flex items-center gap-2.5">
          <span className="grid h-8 w-8 place-items-center rounded-full bg-white">
            <LogoMark className="h-3.5 w-4" id="lg-wa-relance" />
          </span>
          <div className="text-left">
            <p className="text-[13px] font-semibold leading-tight">Salon Élégance</p>
            <p className="text-[10px] opacity-80">Compte professionnel</p>
          </div>
        </div>
      </div>
      <div className="flex min-h-[330px] flex-col gap-2 bg-[#ECE5DD] px-3 py-3 text-left text-[12px] leading-snug text-[#111B21]">
        <span className="mx-auto rounded-md bg-white/80 px-2 py-0.5 text-[10px] text-[#54656F]">AUJOURD’HUI</span>
        <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          Bonjour Mireille, ça fait 6 semaines qu’on ne vous a pas vue&nbsp;! Votre brushing vous attend, et cette semaine on vous offre <b>500 points fidélité</b> sur votre visite.
          <p className="mt-1 text-right text-[10px] text-[#667781]">10:02</p>
        </div>
        <div className="ml-auto max-w-[75%] rounded-lg rounded-tr-none bg-[#D9FDD3] p-2.5 shadow-sm">
          Oh merci&nbsp;! Je passe samedi matin.
          <p className="mt-1 text-right text-[10px] text-[#667781]">10:15 ✓✓</p>
        </div>
        <div className="max-w-[88%] rounded-lg rounded-tl-none bg-white p-2.5 shadow-sm">
          Avec plaisir, à samedi Mireille&nbsp;!
          <p className="mt-1 text-right text-[10px] text-[#667781]">10:16</p>
        </div>
      </div>
    </Phone>
  );
}
