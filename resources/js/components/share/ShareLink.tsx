import { useMemo, useState } from 'react';

import { encodeQr, qrPath } from '../common/qrcode';

/*
 * « Partager mon lien » : copier, WhatsApp (message prérempli), Facebook et
 * QR code PNG à télécharger. Réutilisé dans Paramètres › Ma page en ligne et
 * sur le tableau de bord Location.
 */

function downloadPng(value: string, filename: string) {
    const qr = encodeQr(value, 'M');
    const scale = 12;
    const margin = 4;
    const size = (qr.size + margin * 2) * scale;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000';
    qr.modules.forEach((row, y) => row.forEach((on, x) => on && ctx.fillRect((x + margin) * scale, (y + margin) * scale, scale, scale)));
    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = filename;
    a.click();
}

export default function ShareLink({ url, label, message, filename }: { url: string; label: string; message: string; filename: string }) {
    const [copied, setCopied] = useState(false);
    const qr = useMemo(() => encodeQr(url, 'M'), [url]);
    const dim = qr.size + 4;
    const text = `${message} ${url}`;

    const copy = () => {
        navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <div className="flex flex-wrap items-start gap-4 text-sm">
            <svg viewBox={`0 0 ${dim} ${dim}`} className="h-24 w-24 shrink-0 rounded border border-gray-200" shapeRendering="crispEdges" role="img" aria-label={`QR code : ${label}`}>
                <rect width={dim} height={dim} fill="#fff" />
                <path d={qrPath(qr, 2)} fill="#000" />
            </svg>
            <div className="min-w-0 flex-1 space-y-2">
                <p className="font-semibold">{label}</p>
                <code className="block truncate rounded-lg bg-gray-100 px-3 py-2 text-xs dark:bg-white/5">{url}</code>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className="mdash-btn ghost sm" onClick={copy}>
                        {copied ? 'Copié ✓' : 'Copier'}
                    </button>
                    <a className="mdash-btn ghost sm" href={`https://wa.me/?text=${encodeURIComponent(text)}`} target="_blank" rel="noreferrer">
                        WhatsApp
                    </a>
                    <a className="mdash-btn ghost sm" href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`} target="_blank" rel="noreferrer">
                        Facebook
                    </a>
                    <button type="button" className="mdash-btn ghost sm" onClick={() => downloadPng(url, filename)}>
                        QR code (PNG)
                    </button>
                </div>
            </div>
        </div>
    );
}
