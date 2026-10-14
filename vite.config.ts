import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        // Serveur de développement accessible depuis le conteneur Docker.
        host: process.env.VITE_DEV_SERVER_HOST ?? 'localhost',
        port: Number(process.env.VITE_DEV_SERVER_PORT ?? 5173),
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            // Indispensable pour détecter les modifications d'un dossier Windows monté dans Docker.
            usePolling: process.env.VITE_USE_POLLING === 'true',
        },
    },
    build: {
        sourcemap: false,
    },
});
