import type { ReactNode } from 'react';
import { Icon } from './Icon';
import type { Status } from '@/lib/site';

// Logo NENTIX (charte du 05/11/2026) : hexagone bleu évidé, cœur cyan, nom vectorisé.
export function LogoMark({ className = 'h-8 w-8', light = false }: { className?: string; id?: string; light?: boolean }) {
  return (
    <svg viewBox="0 0 100 100" className={className} aria-hidden="true">
      <path d="M50,0 93.3,25 93.3,75 50,100 6.7,75 6.7,25Z M50,15 80.31,32.5 80.31,67.5 50,85 19.69,67.5 19.69,32.5Z" fill={light ? '#FFFFFF' : '#1D4ED8'} fillRule="evenodd" />
      <path d="M50,32 65.59,41 65.59,59 50,68 34.41,59 34.41,41Z" fill="#06B6D4" />
    </svg>
  );
}

const WORDMARK = 'M4.81586402266289 0.0V-50.0H12.606232294617564L15.93484419263456 -40.01416430594901V0.0ZM39.30594900849859 0.0 9.844192634560907 -37.74787535410765 12.606232294617564 -50.0 42.067988668555245 -12.252124645892351ZM39.30594900849859 0.0 36.331444759206796 -9.985835694050992V-50.0H47.450424929178475V0.0ZM58.082152974504254 0.0V-50.0H69.20113314447592V0.0ZM66.58073654390935 0.0V-9.63172804532578H93.98866855524079V0.0ZM66.58073654390935 -20.892351274787536V-30.1699716713881H91.5099150141643V-20.892351274787536ZM66.58073654390935 -40.43909348441927V-50.0H93.63456090651559V-40.43909348441927ZM102.63739376770539 0.0V-50.0H110.42776203966007L113.75637393767705 -40.01416430594901V0.0ZM137.12747875354108 0.0 107.66572237960341 -37.74787535410765 110.42776203966007 -50.0 139.88951841359773 -12.252124645892351ZM137.12747875354108 0.0 134.1529745042493 -9.985835694050992V-50.0H145.27195467422098V0.0ZM168.1558073654391 0.0V-49.29178470254958H179.27478753541078V0.0ZM152.5042492917847 -40.29745042492918V-50.0H194.92634560906515V-40.29745042492918ZM202.1586402266289 0.0V-50.0H213.27762039660058V0.0ZM255.6373937677054 0.0 241.68555240793202 -22.308781869688385H240.33994334277622L221.07648725212465 -50.0H234.3201133144476L248.05949008498584 -29.036827195467424H249.40509915014164L268.66855524079324 0.0ZM220.1558073654391 0.0 238.6402266288952 -26.770538243626063 247.20963172804534 -21.954674220963174 232.8328611898017 0.0ZM249.8300283286119 -23.58356940509915 241.26062322946177 -28.39943342776204 255.1416430594901 -50.0H267.8186968838527Z';

export function Logo({ light = false, id }: { light?: boolean; id?: string }) {
  return (
    <span className="inline-flex items-center gap-2.5" data-logo={id}>
      <LogoMark light={light} />
      <svg viewBox="108 25 266 50" className={`h-[1.15rem] w-auto ${light ? 'text-white' : 'text-[#0B1F3A]'}`} role="img" aria-label="NENTIX">
        <path transform="translate(103.78,75)" d={WORDMARK} fill="currentColor" />
      </svg>
    </span>
  );
}

type BtnProps = { href: string; children: ReactNode; variant?: 'dark' | 'outline' | 'light' | 'brand'; size?: 'md' | 'lg'; className?: string };

export function Button({ href, children, variant = 'dark', size = 'md', className = '' }: BtnProps) {
  const base = 'inline-flex whitespace-nowrap items-center justify-center gap-2 rounded-full font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue';
  const sizes = { md: 'h-11 px-5 text-[15px]', lg: 'h-[52px] px-7 text-base' };
  const variants = {
    dark: 'bg-navy text-white hover:bg-navy-deep',
    brand: 'bg-gradient-to-r from-blue to-green text-white hover:brightness-105 shadow-[0_8px_24px_-8px_rgba(14,123,214,.6)]',
    outline: 'border border-line bg-white text-navy hover:border-navy/40',
    light: 'bg-white text-navy hover:bg-soft',
  };
  return (
    <a href={href} className={`${base} ${sizes[size]} ${variants[variant]} ${className}`}>
      {children}
    </a>
  );
}

export function Eyebrow({ children, tone = 'blue' }: { children: ReactNode; tone?: 'blue' | 'green' | 'light' }) {
  const tones = { blue: 'text-blue-text', green: 'text-green-text', light: 'text-green' };
  return <p className={`text-[13px] font-bold uppercase tracking-[0.16em] ${tones[tone]}`}>{children}</p>;
}

export function SectionTitle({ eyebrow, title, lead, center = false, light = false }: { eyebrow?: string; title: ReactNode; lead?: ReactNode; center?: boolean; light?: boolean }) {
  return (
    <div className={`flex flex-col gap-4 ${center ? 'items-center text-center mx-auto' : ''} max-w-3xl`}>
      {eyebrow && <Eyebrow tone={light ? 'light' : 'blue'}>{eyebrow}</Eyebrow>}
      <h2 className={`font-display text-[2rem] font-bold leading-[1.08] tracking-[-0.01em] text-balance sm:text-[2.6rem] ${light ? 'text-white' : 'text-navy'}`}>{title}</h2>
      {lead && <p className={`text-lg leading-relaxed ${light ? 'text-[#C9D9E8]' : 'text-muted'}`}>{lead}</p>}
    </div>
  );
}

export function StatusBadge({ status }: { status: Status }) {
  return status === 'dispo' ? (
    <span className="inline-flex items-center gap-1 rounded-full bg-green-soft px-2.5 py-0.5 text-xs font-semibold text-green-text">
      <Icon name="check" className="h-3 w-3" strokeWidth={3} /> Disponible
    </span>
  ) : (
    <span className="inline-flex items-center rounded-full bg-blue-soft px-2.5 py-0.5 text-xs font-semibold text-blue-text">Bientôt</span>
  );
}

export function Container({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`mx-auto w-full max-w-page px-5 sm:px-8 ${className}`}>{children}</div>;
}

export function GradientText({ children }: { children: ReactNode }) {
  return <span className="bg-gradient-to-r from-blue to-green bg-clip-text text-transparent">{children}</span>;
}
