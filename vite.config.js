import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const normalizedAssetUrl = (env.ASSET_URL ?? '').replace(/\/+$/, '');
    const buildBase = normalizedAssetUrl ? `${normalizedAssetUrl}/build/` : '/build/';

    return {
        base: command === 'build' ? buildBase : '/',
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.jsx'],
                refresh: true,
            }),
            tailwindcss(),
            react(),
        ],
        server: {
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
