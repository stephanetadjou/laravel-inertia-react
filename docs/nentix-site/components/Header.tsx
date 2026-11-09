import { discoverMenu, families, featureMenu, links, type MenuItem } from '@/lib/site';
import { Icon } from './Icon';
import { NavDropdown } from './NavDropdown';
import { Logo } from './ui';

// En-tête sombre : Activités et Fonctionnalités s'ouvrent au clic (NavDropdown),
// le menu mobile repose sur <details>, avec une rubrique dépliable pour chacun.
export function Header() {
  return (
    <header className="sticky top-0 z-40 border-b border-white/10 bg-navy-deep/95 backdrop-blur-md">
      <div className="mx-auto flex h-[72px] w-full max-w-[1320px] items-center gap-6 px-5 sm:px-8">
        <a href="/" aria-label="NENTIX, accueil" className="shrink-0">
          <Logo light id="lg-header" />
        </a>

        <nav className="hidden items-center gap-1 lg:flex" aria-label="Navigation principale">
          <NavDropdown label="Activités">
            <div className="grid grid-cols-3 gap-2">
              {families.map((f) => (
                <a key={f.slug} href={`/activites/${f.slug}`} className="flex items-start gap-3 rounded-xl p-3 hover:bg-white/5">
                  <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/5 text-green ring-1 ring-white/10">
                    <Icon name={f.icon} className="h-[18px] w-[18px]" />
                  </span>
                  <span>
                    <span className="block text-[15px] font-semibold text-white">{f.name}</span>
                    <span className="block text-[13px] leading-snug text-[#8FA6BC]">{f.trades.slice(0, 3).join(', ')}</span>
                  </span>
                </a>
              ))}
              <a href="/activites" className="flex items-center gap-2 rounded-xl p-3 text-[14px] font-semibold text-green hover:bg-white/5">
                Toutes les activités <Icon name="arrow" className="h-4 w-4" />
              </a>
            </div>
          </NavDropdown>

          <NavDropdown label="Fonctionnalités">
            <div className="grid grid-cols-3 gap-x-2 gap-y-1">
              {featureMenu.map((col) => (
                <div key={col.title} className="flex flex-col gap-1">
                  <p className="px-3 pb-1 text-xs font-bold uppercase tracking-[0.16em] text-[#8FA6BC]">{col.title}</p>
                  {col.items.map((i) => <FeatureCard key={i.label} item={i} />)}
                </div>
              ))}
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-white/10 pt-4">
              {discoverMenu.map((i) => (
                <a key={i.label} href={i.href} className="inline-flex items-center gap-2 text-[14px] font-semibold text-white/90 hover:text-green">
                  <Icon name={i.icon} className="h-4 w-4 text-green" /> {i.label}
                </a>
              ))}
            </div>
          </NavDropdown>

          <a href="/tarifs" className="rounded-full px-4 py-2 text-[15px] font-medium text-white/90 hover:text-white">Tarifs</a>
          <a href="/whatsapp" className="rounded-full px-4 py-2 text-[15px] font-medium text-white/90 hover:text-white">WhatsApp</a>
        </nav>

        <div className="ml-auto hidden items-center gap-3 lg:flex">
          <a href={links.login} className="whitespace-nowrap px-2 text-[15px] font-semibold text-white/90 hover:text-white">Se connecter</a>
          <a href="/contact" className="inline-flex h-11 items-center whitespace-nowrap rounded-lg border border-white/20 px-4 text-[15px] font-semibold text-white hover:border-white/50">Demander une démo</a>
          <a href={links.signup} className="inline-flex h-11 items-center whitespace-nowrap rounded-lg bg-gradient-to-r from-blue to-green px-5 text-[15px] font-semibold text-white hover:brightness-110">Essai gratuit</a>
        </div>

        <details className="group ml-auto lg:hidden">
          <summary className="inline-flex h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-white/20 px-4 text-[15px] font-semibold text-white [&::-webkit-details-marker]:hidden">
            Menu <Icon name="menu" className="h-5 w-5 group-open:hidden" /><Icon name="close" className="hidden h-5 w-5 group-open:block" />
          </summary>
          <div className="absolute inset-x-0 top-[72px] max-h-[calc(100dvh-72px)] overflow-y-auto border-b border-white/10 bg-navy-deep px-5 pb-6 pt-2 shadow-float">
            <MobileSection label="Activités">
              {families.map((f) => (
                <a key={f.slug} href={`/activites/${f.slug}`} className="flex items-center gap-3 rounded-xl px-2 py-2.5 text-[16px] font-medium text-white">
                  <Icon name={f.icon} className="h-[18px] w-[18px] text-green" /> {f.name}
                </a>
              ))}
            </MobileSection>
            <MobileSection label="Fonctionnalités">
              {featureMenu.map((col) => (
                <div key={col.title}>
                  <p className="px-2 pt-3 text-xs font-bold uppercase tracking-[0.16em] text-[#8FA6BC]">{col.title}</p>
                  {col.items.map((i) => <MenuLink key={i.label} item={i} />)}
                </div>
              ))}
            </MobileSection>
            <div className="my-3 h-px bg-white/10" />
            <a href="/fonctionnalites" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-white">Toutes les fonctionnalités</a>
            <a href="/tarifs" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-white">Tarifs</a>
            <a href="/whatsapp" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-white">Guide WhatsApp</a>
            <a href="/contact" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-white">Demander une démo</a>
            <div className="mt-4 grid grid-cols-2 gap-2">
              <a href={links.login} className="inline-flex h-12 items-center justify-center rounded-lg border border-white/20 font-semibold text-white">Se connecter</a>
              <a href={links.signup} className="inline-flex h-12 items-center justify-center rounded-lg bg-gradient-to-r from-blue to-green font-semibold text-white">Essai gratuit</a>
            </div>
          </div>
        </details>
      </div>
    </header>
  );
}

function MenuLink({ item }: { item: MenuItem }) {
  return (
    <a href={item.href} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-white hover:bg-white/5">
      <Icon name={item.icon} className="h-5 w-5 shrink-0 text-green" />
      <span className="whitespace-nowrap">{item.label}</span>
      {item.soon && <span className="rounded-full bg-blue/20 px-2 py-0.5 text-[11px] font-semibold text-[#8CC8FF]">Bientôt</span>}
    </a>
  );
}

function FeatureCard({ item }: { item: MenuItem }) {
  return (
    <a href={item.href} className="flex items-start gap-3 rounded-xl p-3 hover:bg-white/5">
      <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/5 text-green ring-1 ring-white/10">
        <Icon name={item.icon} className="h-[18px] w-[18px]" />
      </span>
      <span>
        <span className="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-white">
          {item.label}
          {item.soon && <span className="rounded-full bg-blue/20 px-2 py-0.5 text-[11px] font-semibold text-[#8CC8FF]">Bientôt</span>}
        </span>
        {item.desc && <span className="block text-[13px] leading-snug text-[#8FA6BC]">{item.desc}</span>}
      </span>
    </a>
  );
}

function MobileSection({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <details className="group/sec border-b border-white/10">
      <summary className="flex cursor-pointer list-none items-center justify-between px-2 py-3.5 text-[16px] font-semibold text-white [&::-webkit-details-marker]:hidden">
        {label} <Icon name="chevron" className="h-5 w-5 text-[#8FA6BC] transition group-open/sec:rotate-180" />
      </summary>
      <div className="pb-3">{children}</div>
    </details>
  );
}
