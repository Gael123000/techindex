/**
 * Pile des fenêtres modales ouvertes, dans l'ordre d'ouverture.
 *
 * La dernière ouverte passe toujours au premier plan, quel que soit l'ordre
 * des composants dans la page (une fenêtre de la mise en page, comme la
 * confirmation d'identité, peut s'ouvrir par-dessus une fenêtre de la page).
 * Seule celle du dessus réagit au clavier, et le défilement de la page reste
 * bloqué tant qu'une fenêtre est ouverte.
 */
const BASE_LAYER = 50;

const stack: number[] = [];
let lastId = 0;

function syncScrollLock(): void {
    document.body.style.overflow = stack.length > 0 ? 'hidden' : '';
}

/**
 * Inscrit une fenêtre qui s'ouvre ; renvoie son identifiant et son plan (z-index).
 */
export function openModal(): { id: number; layer: number } {
    const id = ++lastId;
    stack.push(id);
    syncScrollLock();

    return { id, layer: BASE_LAYER + stack.length - 1 };
}

export function closeModal(id: number): void {
    const index = stack.indexOf(id);

    if (index !== -1) {
        stack.splice(index, 1);
    }

    syncScrollLock();
}

export function isTopModal(id: number): boolean {
    return stack[stack.length - 1] === id;
}
