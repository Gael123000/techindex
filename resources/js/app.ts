import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { notePageTitle } from './composables/useTabs';

const appName = 'TechIndex';

createInertiaApp({
    title: (title, page) => {
        // Le titre de la page sert aussi de libellé à l'onglet actif.
        notePageTitle(title, page);

        return title ? `${title} · ${appName}` : appName;
    },
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
        const page = pages[`./pages/${name}.vue`];

        if (!page) {
            throw new Error(`Page introuvable : ${name}`);
        }

        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#14b3a4',
        showSpinner: false,
    },
});
