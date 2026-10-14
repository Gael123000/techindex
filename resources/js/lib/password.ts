/**
 * Générateur de mots de passe. Le tirage utilise le générateur
 * cryptographique du navigateur (crypto.getRandomValues), sans biais de
 * modulo ; le mot de passe n'est jamais envoyé ailleurs qu'au formulaire.
 */

export interface PasswordOptions {
    length: number;
    lower: boolean;
    upper: boolean;
    digits: boolean;
    symbols: boolean;
    /** Exclut les caractères faciles à confondre (0/O, 1/l/I...). */
    readable: boolean;
}

export const defaultPasswordOptions: PasswordOptions = {
    length: 24,
    lower: true,
    upper: true,
    digits: true,
    symbols: true,
    readable: true,
};

const SETS = {
    lower: { all: 'abcdefghijklmnopqrstuvwxyz', readable: 'abcdefghijkmnopqrstuvwxyz' },
    upper: { all: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', readable: 'ABCDEFGHJKLMNPQRSTUVWXYZ' },
    digits: { all: '0123456789', readable: '23456789' },
    symbols: { all: '!#$%&()*+,-./:;<=>?@[]^_{|}~', readable: '!#$%&*+-=?@_' },
};

/**
 * Entier aléatoire uniforme dans [0, max[.
 */
function randomIndex(max: number): number {
    const limit = Math.floor(0x100000000 / max) * max;
    const buffer = new Uint32Array(1);

    do {
        crypto.getRandomValues(buffer);
    } while (buffer[0]! >= limit);

    return buffer[0]! % max;
}

export function generatePassword(options: PasswordOptions = defaultPasswordOptions): string {
    const sets = (Object.keys(SETS) as Array<keyof typeof SETS>)
        .filter((key) => options[key])
        .map((key) => (options.readable ? SETS[key].readable : SETS[key].all));

    if (sets.length === 0) {
        sets.push(SETS.lower.readable);
    }

    const length = Math.min(128, Math.max(Math.max(8, sets.length), Math.round(options.length)));
    const pool = sets.join('');

    // Au moins un caractère de chaque famille choisie, puis tirage libre.
    const chars = sets.map((set) => set[randomIndex(set.length)]!);

    while (chars.length < length) {
        chars.push(pool[randomIndex(pool.length)]!);
    }

    // Mélange de Fisher-Yates.
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomIndex(i + 1);
        [chars[i], chars[j]] = [chars[j]!, chars[i]!];
    }

    return chars.join('');
}

/**
 * Estimation de la robustesse (entropie en bits) d'un mot de passe saisi.
 */
export function passwordStrength(value: string): { bits: number; label: string; level: 0 | 1 | 2 | 3 } {
    if (value === '') {
        return { bits: 0, label: '', level: 0 };
    }

    let pool = 0;
    pool += /[a-z]/.test(value) ? 26 : 0;
    pool += /[A-Z]/.test(value) ? 26 : 0;
    pool += /[0-9]/.test(value) ? 10 : 0;
    pool += /[^a-zA-Z0-9]/.test(value) ? 32 : 0;

    const bits = Math.round(value.length * Math.log2(Math.max(pool, 2)));

    if (bits >= 100) {
        return { bits, label: 'Très robuste', level: 3 };
    }

    if (bits >= 70) {
        return { bits, label: 'Robuste', level: 2 };
    }

    if (bits >= 50) {
        return { bits, label: 'Moyen', level: 1 };
    }

    return { bits, label: 'Faible', level: 0 };
}
