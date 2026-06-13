import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

const usePolling = process.env.VITE_USE_POLLING === 'true';
const isInSail = process.env.LARAVEL_SAIL === '1';

export default defineConfig({
    server: {
        /**
         * Avoid `host: true` writing `http://[::]:5173` into `public/hot`: the page is often opened
         * as `http://localhost`, and loading modules from `http://[::]:5174` then fails (CORS / null
         * status). Use loopback so script and HMR URLs match the browser origin people actually use.
         * When running inside Sail (Docker), bind to 0.0.0.0 so the published port is reachable.
         * For phone/LAN testing, temporarily set `host: true` or override `VITE_DEV_SERVER_URL` in `.env`.
         */
        host: isInSail ? '0.0.0.0' : '127.0.0.1',
        strictPort: false,
        watch: {
            ignored: ['**/storage/**', '**/bootstrap/cache/**', '**/vendor/**'],
            ...(usePolling ? { usePolling: true, interval: 400 } : {}),
        },
        hmr: {
            host: '127.0.0.1',
            overlay: true,
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Kalam', {
                    weights: [300, 400, 700],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
