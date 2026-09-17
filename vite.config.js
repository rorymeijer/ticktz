import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

/**
 * The port the dev server listens on, and the one a browser reaches it at.
 *
 * The same number outside Docker, and able to differ inside it: the compose
 * file lets a second checkout publish 5174 while the container still binds
 * 5173.
 */
const port = Number(process.env.VITE_PORT || 5173);
const browserPort = Number(process.env.VITE_BROWSER_PORT || port);

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
        react(),
    ],

    /*
     * Bound on every interface, advertised as localhost.
     *
     * In Docker the dev server has to listen on 0.0.0.0 to be reachable from
     * outside its container, and laravel-vite-plugin writes whatever it is
     * listening on into `public/hot` — which is the URL the *browser* is then
     * told to load the application from. `http://0.0.0.0:5173` is not an
     * address a browser can fetch: Chrome quietly treats it as localhost,
     * Safari and Firefox do not, and the page comes up white with no error on
     * the server anywhere to explain it.
     *
     * `hmr.host` is what the plugin writes instead, so the container binds one
     * address and the browser is handed another — which is the actual shape of
     * the situation, rather than a coincidence that holds on one machine.
     */
    server: {
        host: '0.0.0.0',
        port,
        strictPort: true,
        hmr: {
            host: process.env.VITE_BROWSER_HOST || 'localhost',
            port: browserPort,
        },
    },
});
