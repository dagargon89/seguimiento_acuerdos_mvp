import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    // PWA: manifest + service worker Workbox (generateSW). registerType 'prompt'
    // porque un reload automático perdería formularios a media captura; el
    // banner de ActualizacionSW deja que el usuario decida cuándo recargar.
    VitePWA({
      registerType: 'prompt',
      includeAssets: ['favicon.svg', 'icons/*.png'],
      manifest: {
        name: 'Panel de Acuerdos · Participa Juárez',
        short_name: 'Acuerdos',
        description: 'Seguimiento de acuerdos de Plan Juárez',
        lang: 'es',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        theme_color: '#0f1319',
        background_color: '#0f1319',
        icons: [
          { src: '/icons/pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
        navigateFallback: '/index.html',
        // /api/ nunca debe caer al shell; /__/ cubre el proxy /__/auth/ de
        // Firebase por si authDomain apuntara al dominio propio.
        navigateFallbackDenylist: [/^\/api\//, /^\/__\//],
        cleanupOutdatedCaches: true,
        clientsClaim: true, // skipWaiting queda en false: modo prompt
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'google-fonts-css',
              expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 },
            },
          },
          {
            urlPattern: /^https:\/\/fonts\.gstatic\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'google-fonts-webfonts',
              expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 365 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          // Nada para /api/ ni orígenes de Firebase: sin ruta de caché, el SW
          // no responde y las peticiones pasan directo a la red.
        ],
      },
      devOptions: { enabled: false }, // el SW no interfiere en npm run dev
    }),
  ],
});
