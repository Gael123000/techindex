/**
 * Découpe un texte en segments pour surligner les mots recherchés, sans
 * jamais produire de HTML (le rendu reste échappé par Vue).
 */
export interface HighlightSegment {
    text: string;
    match: boolean;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function highlight(text: string | null | undefined, term: string): HighlightSegment[] {
    const source = text ?? '';
    const words = term
        .trim()
        .split(/\s+/)
        .filter((word) => word.length >= 2)
        .map(escapeRegExp);

    if (source === '' || words.length === 0) {
        return [{ text: source, match: false }];
    }

    const pattern = new RegExp(`(${words.join('|')})`, 'gi');

    return source
        .split(pattern)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, match: words.some((word) => new RegExp(`^${word}$`, 'i').test(part)) }));
}
