import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

type FilterValue = string | number | boolean | null | undefined;

/**
 * Filtres, tri et pagination côté serveur : chaque changement relance une
 * visite Inertia en conservant l'état de la page (saisie non interrompue).
 */
export function useFilters<T extends Record<string, FilterValue>>(url: () => string, initial: T, debounceMs = 300) {
    const filters = reactive({ ...initial }) as T;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function apply(extra: Record<string, FilterValue> = {}): void {
        const query: Record<string, string | number | boolean> = {};

        for (const [key, value] of Object.entries({ ...filters, ...extra })) {
            if (value !== null && value !== undefined && value !== '' && value !== false) {
                query[key] = value;
            }
        }

        router.get(url(), query, { preserveState: true, preserveScroll: true, replace: true });
    }

    watch(
        () => ({ ...filters }),
        () => {
            clearTimeout(timer);
            timer = setTimeout(() => apply({ page: undefined }), debounceMs);
        },
        { deep: true },
    );

    function sortBy(column: string): void {
        const current = (filters as Record<string, FilterValue>).sort;
        const direction = (filters as Record<string, FilterValue>).direction;

        Object.assign(filters, {
            sort: column,
            direction: current === column && direction === 'asc' ? 'desc' : 'asc',
        });
    }

    function reset(): void {
        for (const key of Object.keys(filters)) {
            (filters as Record<string, FilterValue>)[key] = key === 'sort' || key === 'direction' ? (initial[key] as FilterValue) : '';
        }
    }

    return { filters, apply, sortBy, reset };
}
