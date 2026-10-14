const TIME_ZONE = 'Europe/Paris';

/**
 * Formate une date (AAAA-MM-JJ ou ISO 8601) au format français.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = value.length === 10 ? new Date(`${value}T12:00:00Z`) : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: TIME_ZONE }).format(date);
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: TIME_ZONE }).format(date);
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} o`;
    }

    const units = ['Ko', 'Mo', 'Go'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} ${units[unit]}`;
}

/**
 * Nombre de jours entre aujourd'hui et une date (négatif si dépassée).
 */
export function daysUntil(value: string | null | undefined): number | null {
    if (!value) {
        return null;
    }

    const target = new Date(`${value.slice(0, 10)}T00:00:00Z`).getTime();
    const today = new Date();
    const todayUtc = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());

    return Math.round((target - todayUtc) / 86_400_000);
}

export function formatCurrency(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return Number(value).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
}

export function pluralize(count: number, singular: string, plural?: string): string {
    return `${count.toLocaleString('fr-FR')} ${count > 1 ? (plural ?? `${singular}s`) : singular}`;
}
