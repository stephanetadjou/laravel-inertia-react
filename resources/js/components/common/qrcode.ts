/**
 * Encodeur QR code autonome (norme ISO/IEC 18004, mode octet), sans
 * dépendance : adapté de l'algorithme de référence de Project Nayuki
 * (licence MIT). Sert aux affiches « Scannez pour rejoindre le programme »
 * des boutiques : le lien WhatsApp est encodé tel quel, en UTF-8.
 *
 * Le niveau de correction demandé est un minimum : il est relevé
 * automatiquement s'il tient dans la même version (affiche plus robuste
 * aux reflets et aux salissures, sans agrandir le code).
 */

export type QrEcc = 'L' | 'M' | 'Q' | 'H';

const ECC_ORDER: QrEcc[] = ['L', 'M', 'Q', 'H'];
const ECC_FORMAT_BITS: Record<QrEcc, number> = { L: 1, M: 0, Q: 3, H: 2 };

// Index = version (1 à 40), la case 0 est inutilisée.
const ECC_CODEWORDS_PER_BLOCK: Record<QrEcc, number[]> = {
    L: [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    M: [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
    Q: [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    H: [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
};

const NUM_ERROR_CORRECTION_BLOCKS: Record<QrEcc, number[]> = {
    L: [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
    M: [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
    Q: [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
    H: [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
};

export interface QrMatrix {
    size: number;
    /** modules[y][x] : true = module sombre. */
    modules: boolean[][];
}

const getBit = (value: number, i: number): boolean => ((value >>> i) & 1) !== 0;

function numRawDataModules(ver: number): number {
    let result = (16 * ver + 128) * ver + 64;
    if (ver >= 2) {
        const numAlign = Math.floor(ver / 7) + 2;
        result -= (25 * numAlign - 10) * numAlign - 55;
        if (ver >= 7) result -= 36;
    }
    return result;
}

function numDataCodewords(ver: number, ecc: QrEcc): number {
    return Math.floor(numRawDataModules(ver) / 8) - ECC_CODEWORDS_PER_BLOCK[ecc][ver] * NUM_ERROR_CORRECTION_BLOCKS[ecc][ver];
}

/** Bits utilisés par un segment octet de `length` octets dans cette version. */
function segmentBits(ver: number, length: number): number {
    return 4 + (ver < 10 ? 8 : 16) + 8 * length;
}

// --- Reed-Solomon sur GF(2^8), polynôme 0x11D ---

function rsMultiply(x: number, y: number): number {
    let z = 0;
    for (let i = 7; i >= 0; i--) {
        z = (z << 1) ^ ((z >>> 7) * 0x11d);
        z ^= ((y >>> i) & 1) * x;
    }
    return z;
}

function rsDivisor(degree: number): number[] {
    const result = new Array<number>(degree).fill(0);
    result[degree - 1] = 1;
    let root = 1;
    for (let i = 0; i < degree; i++) {
        for (let j = 0; j < result.length; j++) {
            result[j] = rsMultiply(result[j], root);
            if (j + 1 < result.length) result[j] ^= result[j + 1];
        }
        root = rsMultiply(root, 0x02);
    }
    return result;
}

function rsRemainder(data: number[], divisor: number[]): number[] {
    const result = divisor.map(() => 0);
    for (const b of data) {
        const factor = b ^ (result.shift() as number);
        result.push(0);
        divisor.forEach((coef, i) => (result[i] ^= rsMultiply(coef, factor)));
    }
    return result;
}

class Builder {
    readonly size: number;
    readonly modules: boolean[][];
    private readonly isFunction: boolean[][];

    constructor(
        private readonly version: number,
        private readonly ecc: QrEcc,
    ) {
        this.size = version * 4 + 17;
        this.modules = Array.from({ length: this.size }, () => new Array<boolean>(this.size).fill(false));
        this.isFunction = Array.from({ length: this.size }, () => new Array<boolean>(this.size).fill(false));
    }

    build(dataCodewords: number[]): QrMatrix {
        this.drawFunctionPatterns();
        this.drawCodewords(this.addEccAndInterleave(dataCodewords));

        let bestMask = 0;
        let minPenalty = Infinity;
        for (let mask = 0; mask < 8; mask++) {
            this.applyMask(mask);
            this.drawFormatBits(mask);
            const penalty = this.penaltyScore();
            if (penalty < minPenalty) {
                bestMask = mask;
                minPenalty = penalty;
            }
            this.applyMask(mask); // XOR : réappliquer annule le masque
        }
        this.applyMask(bestMask);
        this.drawFormatBits(bestMask);

        return { size: this.size, modules: this.modules };
    }

    private setFunction(x: number, y: number, dark: boolean): void {
        this.modules[y][x] = dark;
        this.isFunction[y][x] = true;
    }

    private drawFunctionPatterns(): void {
        for (let i = 0; i < this.size; i++) {
            this.setFunction(6, i, i % 2 === 0);
            this.setFunction(i, 6, i % 2 === 0);
        }

        this.drawFinder(3, 3);
        this.drawFinder(this.size - 4, 3);
        this.drawFinder(3, this.size - 4);

        const positions = this.alignmentPositions();
        const n = positions.length;
        for (let i = 0; i < n; i++) {
            for (let j = 0; j < n; j++) {
                if (!((i === 0 && j === 0) || (i === 0 && j === n - 1) || (i === n - 1 && j === 0))) {
                    this.drawAlignment(positions[i], positions[j]);
                }
            }
        }

        this.drawFormatBits(0); // réserve la zone, réécrite avec le masque retenu
        this.drawVersion();
    }

    private drawFinder(x: number, y: number): void {
        for (let dy = -4; dy <= 4; dy++) {
            for (let dx = -4; dx <= 4; dx++) {
                const dist = Math.max(Math.abs(dx), Math.abs(dy));
                const xx = x + dx;
                const yy = y + dy;
                if (xx >= 0 && xx < this.size && yy >= 0 && yy < this.size) {
                    this.setFunction(xx, yy, dist !== 2 && dist !== 4);
                }
            }
        }
    }

    private drawAlignment(x: number, y: number): void {
        for (let dy = -2; dy <= 2; dy++) {
            for (let dx = -2; dx <= 2; dx++) {
                this.setFunction(x + dx, y + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
            }
        }
    }

    private alignmentPositions(): number[] {
        if (this.version === 1) return [];
        const numAlign = Math.floor(this.version / 7) + 2;
        const step = Math.floor((this.version * 8 + numAlign * 3 + 5) / (numAlign * 4 - 4)) * 2;
        const result = [6];
        for (let pos = this.size - 7; result.length < numAlign; pos -= step) {
            result.splice(1, 0, pos);
        }
        return result;
    }

    private drawFormatBits(mask: number): void {
        const data = (ECC_FORMAT_BITS[this.ecc] << 3) | mask;
        let rem = data;
        for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
        const bits = ((data << 10) | rem) ^ 0x5412;

        for (let i = 0; i <= 5; i++) this.setFunction(8, i, getBit(bits, i));
        this.setFunction(8, 7, getBit(bits, 6));
        this.setFunction(8, 8, getBit(bits, 7));
        this.setFunction(7, 8, getBit(bits, 8));
        for (let i = 9; i < 15; i++) this.setFunction(14 - i, 8, getBit(bits, i));

        for (let i = 0; i < 8; i++) this.setFunction(this.size - 1 - i, 8, getBit(bits, i));
        for (let i = 8; i < 15; i++) this.setFunction(8, this.size - 15 + i, getBit(bits, i));
        this.setFunction(8, this.size - 8, true);
    }

    private drawVersion(): void {
        if (this.version < 7) return;
        let rem = this.version;
        for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
        const bits = (this.version << 12) | rem;
        for (let i = 0; i < 18; i++) {
            const bit = getBit(bits, i);
            const a = this.size - 11 + (i % 3);
            const b = Math.floor(i / 3);
            this.setFunction(a, b, bit);
            this.setFunction(b, a, bit);
        }
    }

    private addEccAndInterleave(data: number[]): number[] {
        const numBlocks = NUM_ERROR_CORRECTION_BLOCKS[this.ecc][this.version];
        const blockEccLen = ECC_CODEWORDS_PER_BLOCK[this.ecc][this.version];
        const rawCodewords = Math.floor(numRawDataModules(this.version) / 8);
        const numShortBlocks = numBlocks - (rawCodewords % numBlocks);
        const shortBlockLen = Math.floor(rawCodewords / numBlocks);

        const divisor = rsDivisor(blockEccLen);
        const blocks: number[][] = [];
        for (let i = 0, k = 0; i < numBlocks; i++) {
            const dat = data.slice(k, k + shortBlockLen - blockEccLen + (i < numShortBlocks ? 0 : 1));
            k += dat.length;
            const ecc = rsRemainder(dat, divisor);
            if (i < numShortBlocks) dat.push(0);
            blocks.push(dat.concat(ecc));
        }

        const result: number[] = [];
        for (let i = 0; i < blocks[0].length; i++) {
            blocks.forEach((block, j) => {
                if (i !== shortBlockLen - blockEccLen || j >= numShortBlocks) result.push(block[i]);
            });
        }
        return result;
    }

    private drawCodewords(data: number[]): void {
        let i = 0;
        for (let right = this.size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let vert = 0; vert < this.size; vert++) {
                for (let j = 0; j < 2; j++) {
                    const x = right - j;
                    const upward = ((right + 1) & 2) === 0;
                    const y = upward ? this.size - 1 - vert : vert;
                    if (!this.isFunction[y][x] && i < data.length * 8) {
                        this.modules[y][x] = getBit(data[i >>> 3], 7 - (i & 7));
                        i++;
                    }
                }
            }
        }
    }

    private applyMask(mask: number): void {
        for (let y = 0; y < this.size; y++) {
            for (let x = 0; x < this.size; x++) {
                let invert: boolean;
                switch (mask) {
                    case 0: invert = (x + y) % 2 === 0; break;
                    case 1: invert = y % 2 === 0; break;
                    case 2: invert = x % 3 === 0; break;
                    case 3: invert = (x + y) % 3 === 0; break;
                    case 4: invert = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break;
                    case 5: invert = ((x * y) % 2) + ((x * y) % 3) === 0; break;
                    case 6: invert = (((x * y) % 2) + ((x * y) % 3)) % 2 === 0; break;
                    default: invert = (((x + y) % 2) + ((x * y) % 3)) % 2 === 0; break;
                }
                if (!this.isFunction[y][x] && invert) this.modules[y][x] = !this.modules[y][x];
            }
        }
    }

    /** Pénalités N1 à N4 de la norme, pour choisir le masque le plus lisible. */
    private penaltyScore(): number {
        const size = this.size;
        const at = (x: number, y: number, horizontal: boolean) => (horizontal ? this.modules[y][x] : this.modules[x][y]);
        let result = 0;

        for (const horizontal of [true, false]) {
            for (let a = 0; a < size; a++) {
                // N1 : suites d'au moins 5 modules de même couleur.
                let run = 1;
                for (let b = 1; b < size; b++) {
                    if (at(b, a, horizontal) === at(b - 1, a, horizontal)) {
                        run++;
                    } else {
                        if (run >= 5) result += run - 2;
                        run = 1;
                    }
                }
                if (run >= 5) result += run - 2;

                // N3 : motifs 1:1:3:1:1 bordés de 4 modules clairs (faux repères).
                for (let b = 0; b + 7 <= size; b++) {
                    const core =
                        at(b, a, horizontal) && !at(b + 1, a, horizontal) && at(b + 2, a, horizontal) && at(b + 3, a, horizontal) &&
                        at(b + 4, a, horizontal) && !at(b + 5, a, horizontal) && at(b + 6, a, horizontal);
                    if (!core) continue;
                    const lightBefore = [1, 2, 3, 4].every((k) => b - k < 0 || !at(b - k, a, horizontal));
                    const lightAfter = [7, 8, 9, 10].every((k) => b + k >= size || !at(b + k, a, horizontal));
                    if (lightBefore || lightAfter) result += 40;
                }
            }
        }

        // N2 : blocs 2×2 de même couleur.
        for (let y = 0; y < size - 1; y++) {
            for (let x = 0; x < size - 1; x++) {
                const c = this.modules[y][x];
                if (c === this.modules[y][x + 1] && c === this.modules[y + 1][x] && c === this.modules[y + 1][x + 1]) result += 3;
            }
        }

        // N4 : équilibre clair / sombre.
        const dark = this.modules.reduce((sum, row) => sum + row.filter(Boolean).length, 0);
        const total = size * size;
        const k = Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1;
        result += Math.max(0, k) * 10;

        return result;
    }
}

/** Encode un texte (UTF-8) en QR code. Lève une erreur au-delà de la capacité maximale. */
export function encodeQr(text: string, minEcc: QrEcc = 'M'): QrMatrix {
    const bytes = Array.from(new TextEncoder().encode(text));

    let version = 1;
    for (; ; version++) {
        if (version > 40) throw new Error('Texte trop long pour un QR code.');
        if (segmentBits(version, bytes.length) <= numDataCodewords(version, minEcc) * 8) break;
    }

    let ecc = minEcc;
    for (const candidate of ECC_ORDER.slice(ECC_ORDER.indexOf(minEcc) + 1)) {
        if (segmentBits(version, bytes.length) <= numDataCodewords(version, candidate) * 8) ecc = candidate;
    }

    const capacityBits = numDataCodewords(version, ecc) * 8;
    const bits: number[] = [];
    const append = (value: number, length: number) => {
        for (let i = length - 1; i >= 0; i--) bits.push((value >>> i) & 1);
    };
    append(0b0100, 4); // mode octet
    append(bytes.length, version < 10 ? 8 : 16);
    bytes.forEach((b) => append(b, 8));
    append(0, Math.min(4, capacityBits - bits.length)); // terminateur
    append(0, (8 - (bits.length % 8)) % 8);
    for (let pad = 0xec; bits.length < capacityBits; pad ^= 0xec ^ 0x11) append(pad, 8);

    const codewords: number[] = [];
    for (let i = 0; i < bits.length; i += 8) {
        codewords.push(bits.slice(i, i + 8).reduce((acc, bit) => (acc << 1) | bit, 0));
    }

    return new Builder(version, ecc).build(codewords);
}

/** Chemin SVG des modules sombres (1 unité = 1 module), décalé de la marge. */
export function qrPath({ size, modules }: QrMatrix, margin = 4): string {
    const parts: string[] = [];
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            if (modules[y][x]) parts.push(`M${x + margin},${y + margin}h1v1h-1z`);
        }
    }
    return parts.join('');
}
