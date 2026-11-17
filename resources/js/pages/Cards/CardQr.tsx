import { useMemo } from 'react';

import { encodeQr, qrPath } from '../../components/common/qrcode';

/** QR code d'une carte digitale (SVG net, à l'écran comme à l'impression). */
export default function CardQr({ value, className = 'h-28 w-28', color = '#000' }: { value: string; className?: string; color?: string }) {
    const qr = useMemo(() => encodeQr(value, 'M'), [value]);
    const margin = 2;
    const dim = qr.size + margin * 2;

    return (
        <svg viewBox={`0 0 ${dim} ${dim}`} className={className} shapeRendering="crispEdges" role="img" aria-label="QR code de la carte">
            <rect width={dim} height={dim} fill="#fff" />
            <path d={qrPath(qr, margin)} fill={color} />
        </svg>
    );
}

/** Télécharge le QR en SVG (impression chez l'imprimeur, flyers, véhicules). */
export function downloadQr(value: string, filename: string) {
    const qr = encodeQr(value, 'M');
    const dim = qr.size + 8;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${dim} ${dim}" shape-rendering="crispEdges"><rect width="${dim}" height="${dim}" fill="#fff"/><path d="${qrPath(qr, 4)}" fill="#000"/></svg>`;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
}
