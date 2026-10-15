import type { RecordFieldCondition, RecordFieldDefinition, RecordSection, RecordValue } from '@/types';

/**
 * Règles communes du constructeur de fiches côté navigateur. La même logique
 * est appliquée par le serveur (App\Services\Records\RecordValues), qui seul
 * fait foi : un champ masqué n'y est ni validé ni modifié.
 */
export function conditionMatches(condition: RecordFieldCondition, value: unknown): boolean {
    const values = (Array.isArray(value) ? value : [value]).filter((item) => item !== null && item !== undefined && item !== '').map(String);
    const expected = (condition.value ?? []).filter((item) => item !== '').map(String);
    const truthy = value === true || value === 1 || value === '1' || value === 'true' || value === 'on';

    switch (condition.operator) {
        case 'filled':
            return values.length > 0;
        case 'empty':
            return values.length === 0;
        case 'checked':
            return truthy;
        case 'unchecked':
            return !truthy;
        case 'not_equals':
            return !values.some((item) => expected.includes(item));
        default:
            return values.some((item) => expected.includes(item));
    }
}

export function isFieldVisible(
    field: RecordFieldDefinition,
    values: Record<string, RecordValue>,
    builtin: Record<string, unknown> = {},
): boolean {
    const condition = field.visibility;

    if (!condition || !condition.field) {
        return true;
    }

    const value = condition.field in values ? values[condition.field] : builtin[condition.field];

    return conditionMatches(condition, value);
}

/** Classes de grille complètes (repérées par Tailwind à la compilation). */
const GRID_COLUMNS: Record<number, string> = {
    1: 'sm:grid-cols-1',
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
};

const SPANS: Record<string, string> = {
    '1': '',
    '2': 'sm:col-span-2',
    full: 'sm:col-span-full',
};

export function gridClass(section: RecordSection): string {
    return GRID_COLUMNS[section.columns] ?? GRID_COLUMNS[2];
}

export function spanClass(width: string, columns: number): string {
    if (columns <= 1) {
        return '';
    }

    return SPANS[width] ?? '';
}

/** Champs d'une section, dans l'ordre ; les champs d'une section disparue vont dans la première. */
export function fieldsOfSection(section: RecordSection, sections: RecordSection[], fields: RecordFieldDefinition[]): RecordFieldDefinition[] {
    const known = sections.map((item) => item.key);
    const first = sections[0]?.key;

    return fields
        .filter((field) => (field.section && known.includes(field.section) ? field.section : first) === section.key)
        .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
}

export const CONDITION_OPERATORS: Array<{ value: RecordFieldCondition['operator']; label: string; needsValue: boolean }> = [
    { value: 'equals', label: 'vaut l\'une des valeurs', needsValue: true },
    { value: 'not_equals', label: 'ne vaut aucune des valeurs', needsValue: true },
    { value: 'filled', label: 'est renseigné', needsValue: false },
    { value: 'empty', label: 'est vide', needsValue: false },
    { value: 'checked', label: 'est coché', needsValue: false },
    { value: 'unchecked', label: 'n\'est pas coché', needsValue: false },
];

/** Identifiant technique proposé à partir d'un libellé (même règle que le serveur). */
export function suggestIdentifier(label: string, fallback = 'champ'): string {
    let name = label
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/_+/g, '_');

    if (name === '' || !/^[a-z]/.test(name)) {
        name = name === '' ? fallback : `${fallback}_${name}`;
    }

    return name.slice(0, 40).replace(/_+$/, '');
}
