import { useState } from 'react';

/**
 * Petits graphiques SVG maison du tableau de bord (pas de dépendance de
 * graphe) : histogramme empilé des messages et courbe du CA vs période
 * précédente. Les couleurs viennent des variables CSS de .mdash.
 */

export interface Bucket {
    start: string;
    end: string;
}

const W = 760;

export function formatNumber(n: number): string {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n));
}

/** 1 234 567 → « 1,2 M », 45 000 → « 45 k ». */
export function formatCompact(n: number): string {
    if (Math.abs(n) >= 1e6) {
        return (n / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' M';
    }
    if (Math.abs(n) >= 1e4) {
        return formatNumber(n / 1e3) + ' k';
    }
    return formatNumber(n);
}

function parseDay(day: string): Date {
    const [y, m, d] = day.split('-').map(Number);
    return new Date(y, m - 1, d);
}

export function bucketLabel(bucket: Bucket): string {
    const fmt = (day: string) => parseDay(day).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' }).replace('.', '');
    return bucket.start === bucket.end ? fmt(bucket.start) : `${fmt(bucket.start)} – ${fmt(bucket.end)}`;
}

function shortLabel(bucket: Bucket): string {
    return parseDay(bucket.start).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' }).replace('.', '');
}

/** Graduation « ronde » de l'axe Y. */
function niceScale(max: number, ticks: number): { top: number; step: number } {
    if (max <= 0) {
        return { top: ticks, step: 1 };
    }
    const magnitude = Math.pow(10, Math.floor(Math.log10(max)));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => max / s <= ticks) ?? magnitude * 10;
    const safeStep = step < 1 ? 1 : step;
    return { top: Math.ceil(max / safeStep) * safeStep, step: safeStep };
}

interface Frame {
    H: number;
    L: number;
    R: number;
    T: number;
    B: number;
}

function Axes({ frame, top, step, n, x, labels, format }: {
    frame: Frame;
    top: number;
    step: number;
    n: number;
    x: (i: number) => number;
    labels: string[];
    format: (v: number) => string;
}) {
    const { H, L, R, T, B } = frame;
    const y = (v: number) => T + (1 - v / top) * (H - T - B);
    const lines = [];
    for (let v = 0; v <= top + 1e-9; v += step) {
        lines.push(
            <g key={v}>
                <line x1={L} x2={W - R} y1={y(v)} y2={y(v)} stroke="var(--grid)" />
                <text x={L - 8} y={y(v) + 3.5} textAnchor="end">
                    {v ? format(v) : '0'}
                </text>
            </g>,
        );
    }
    const every = Math.max(1, Math.ceil(n / 7));
    const xLabels = labels.map((label, i) =>
        i % every && i !== n - 1 ? null : (
            <text key={i} x={x(i)} y={H - 8} textAnchor={n === 1 ? 'middle' : i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>
                {label}
            </text>
        ),
    );
    return (
        <>
            {lines}
            {xLabels}
        </>
    );
}

interface Tip {
    left: number;
    top: number;
    title: string;
    lines: string[];
}

function Tooltip({ tip }: { tip: Tip | null }) {
    if (!tip) {
        return null;
    }
    return (
        <div className="mdash-tip" style={{ left: `${tip.left}%`, top: `${tip.top}%` }}>
            <b>{tip.title}</b>
            {tip.lines.map((line) => (
                <div key={line}>{line}</div>
            ))}
        </div>
    );
}

export function MessagesChart({ series }: { series: (Bucket & { sent: number; failed: number })[] }) {
    const [hover, setHover] = useState<number | null>(null);
    const frame: Frame = { H: 240, L: 46, R: 10, T: 12, B: 28 };
    const n = series.length;
    const max = Math.max(0, ...series.map((d) => d.sent + d.failed)) * 1.1;
    const { top, step } = niceScale(max, 5);
    const slot = (W - frame.L - frame.R) / Math.max(1, n);
    const x = (i: number) => frame.L + slot * (i + 0.5);
    const y = (v: number) => frame.T + (1 - v / top) * (frame.H - frame.T - frame.B);
    const bw = Math.max(2, Math.min(28, slot * 0.6));

    const tip: Tip | null =
        hover === null
            ? null
            : {
                  left: (x(hover) / W) * 100,
                  top: (y(series[hover].sent + series[hover].failed) / frame.H) * 100,
                  title: bucketLabel(series[hover]),
                  lines: [`${formatNumber(series[hover].sent)} envoyés`, `${formatNumber(series[hover].failed)} échecs`],
              };

    return (
        <div style={{ position: 'relative' }}>
            <svg className="mdash-chart" viewBox={`0 0 ${W} ${frame.H}`} role="img" aria-label="Messages WhatsApp envoyés et échecs par période">
                <Axes frame={frame} top={top} step={step} n={n} x={x} labels={series.map(shortLabel)} format={formatCompact} />
                {series.map((d, i) => (
                    <g key={d.start} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
                        <rect x={x(i) - slot / 2} y={frame.T} width={slot} height={frame.H - frame.T - frame.B} fill="transparent" />
                        {d.sent > 0 && (
                            <rect x={x(i) - bw / 2} y={y(d.sent)} width={bw} height={y(0) - y(d.sent)} rx={1.5} fill="var(--brand-2)" opacity={hover === i ? 1 : 0.85} />
                        )}
                        {d.failed > 0 && (
                            <rect
                                x={x(i) - bw / 2}
                                y={y(d.sent + d.failed)}
                                width={bw}
                                height={y(d.sent) - y(d.sent + d.failed)}
                                rx={1.5}
                                fill="var(--bad)"
                            />
                        )}
                    </g>
                ))}
            </svg>
            <Tooltip tip={tip} />
        </div>
    );
}

export function SalesChart({ series, height = 260 }: { series: (Bucket & { revenue: number; previous: number })[]; height?: number }) {
    const [hover, setHover] = useState<number | null>(null);
    const frame: Frame = { H: height, L: 52, R: 12, T: 14, B: 28 };
    const n = series.length;
    const max = Math.max(0, ...series.map((d) => Math.max(d.revenue, d.previous))) * 1.12;
    const { top, step } = niceScale(max, 4);
    const x = (i: number) => (n === 1 ? (frame.L + W - frame.R) / 2 : frame.L + (i / (n - 1)) * (W - frame.L - frame.R));
    const y = (v: number) => frame.T + (1 - v / top) * (frame.H - frame.T - frame.B);
    const path = (values: number[]) => values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join('');
    const current = series.map((d) => d.revenue);
    const previous = series.map((d) => d.previous);
    const slot = (W - frame.L - frame.R) / Math.max(1, n);

    const tip: Tip | null =
        hover === null
            ? null
            : {
                  left: (x(hover) / W) * 100,
                  top: (y(Math.max(series[hover].revenue, series[hover].previous)) / frame.H) * 100,
                  title: bucketLabel(series[hover]),
                  lines: [`${formatNumber(series[hover].revenue)} FCFA`, `Période préc. : ${formatNumber(series[hover].previous)} FCFA`],
              };

    return (
        <div style={{ position: 'relative' }}>
            <svg className="mdash-chart" viewBox={`0 0 ${W} ${frame.H}`} role="img" aria-label="Chiffre d'affaires par période, comparé à la période précédente">
                <defs>
                    <linearGradient id="mdash-ca-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stopColor="var(--brand-2)" stopOpacity="0.28" />
                        <stop offset="1" stopColor="var(--brand-2)" stopOpacity="0" />
                    </linearGradient>
                </defs>
                <Axes frame={frame} top={top} step={step} n={n} x={x} labels={series.map(shortLabel)} format={formatCompact} />
                {n > 1 && (
                    <>
                        <path d={path(previous)} fill="none" stroke="var(--accent)" strokeWidth={1.5} strokeDasharray="4 3" />
                        <path d={`${path(current)}L${x(n - 1)} ${y(0)}L${x(0)} ${y(0)}Z`} fill="url(#mdash-ca-fill)" />
                        <path d={path(current)} fill="none" stroke="var(--brand)" strokeWidth={2.4} strokeLinejoin="round" />
                    </>
                )}
                {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={frame.T} y2={y(0)} stroke="var(--line)" />}
                {series.map((d, i) => (
                    <g key={d.start}>
                        {(n === 1 || hover === i) && (
                            <>
                                <circle cx={x(i)} cy={y(d.previous)} r={3.5} fill="var(--accent)" />
                                <circle cx={x(i)} cy={y(d.revenue)} r={4.5} fill="var(--brand)" stroke="var(--surface)" strokeWidth={2} />
                            </>
                        )}
                        <rect
                            x={x(i) - slot / 2}
                            y={frame.T}
                            width={slot}
                            height={frame.H - frame.T - frame.B}
                            fill="transparent"
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                        />
                    </g>
                ))}
            </svg>
            <Tooltip tip={tip} />
        </div>
    );
}

export function ConsentDonut({ optIn, unknown, optOut }: { optIn: number; unknown: number; optOut: number }) {
    const total = optIn + unknown + optOut;
    const parts: [number, string][] = [
        [optIn, 'var(--ok)'],
        [unknown, 'var(--faint)'],
        [optOut, 'var(--bad)'],
    ];
    const r = 64;
    const c = 2 * Math.PI * r;
    let offset = 0;

    return (
        <svg viewBox="0 0 160 160" role="img" aria-label={`${optIn} contacts opt-in sur ${total}`}>
            <circle cx={80} cy={80} r={r} fill="none" stroke="var(--surface-2)" strokeWidth={20} />
            {total > 0 &&
                parts.map(([value, color]) => {
                    const length = (value / total) * c;
                    const el = (
                        <circle
                            key={color}
                            cx={80}
                            cy={80}
                            r={r}
                            fill="none"
                            stroke={color}
                            strokeWidth={20}
                            strokeDasharray={`${Math.max(0, length - (value > 0 && value < total ? 1.5 : 0))} ${c}`}
                            strokeDashoffset={-offset}
                            transform="rotate(-90 80 80)"
                        />
                    );
                    offset += length;
                    return value > 0 ? el : null;
                })}
            <text x={80} y={78} textAnchor="middle" style={{ fontWeight: 700, fontSize: 24, fill: 'var(--ink)' }}>
                {formatNumber(optIn)}
            </text>
            <text x={80} y={97} textAnchor="middle" style={{ fontSize: 11, fill: 'var(--muted)' }}>
                joignables
            </text>
        </svg>
    );
}
