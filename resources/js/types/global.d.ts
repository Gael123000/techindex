import type { route as routeFn } from 'ziggy-js';
import type { SharedProps } from './index';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof routeFn;
    }
}

export {};
