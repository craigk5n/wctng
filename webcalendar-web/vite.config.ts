import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { visualizer } from 'rollup-plugin-visualizer';

const MANUAL_CHUNKS: Record<string, string[]> = {
  // FullCalendar — only needed on calendar page
  fullcalendar: [
    '@fullcalendar/core',
    '@fullcalendar/daygrid',
    '@fullcalendar/timegrid',
    '@fullcalendar/list',
    '@fullcalendar/multimonth',
    '@fullcalendar/interaction',
    '@fullcalendar/react',
    '@fullcalendar/rrule',
    'rrule',
  ],
  // TipTap editor — only needed when editing descriptions
  tiptap: ['@tiptap/react', '@tiptap/starter-kit', '@tiptap/extension-link'],
  // i18n — loaded early but separable
  i18n: ['i18next', 'react-i18next', 'i18next-http-backend'],
  // React core — cached long-term
  // react-router (not just react-router-dom) since the v7 package collapse:
  // react-router-dom is now a re-export shim and the code lives in react-router.
  'react-vendor': ['react', 'react-dom', 'react-router', 'react-router-dom'],
  // UI framework
  'ui-vendor': [
    '@radix-ui/react-dialog',
    '@radix-ui/react-dropdown-menu',
    '@radix-ui/react-label',
    '@radix-ui/react-select',
    '@radix-ui/react-separator',
    '@radix-ui/react-slot',
    '@radix-ui/react-toast',
    '@tanstack/react-query',
    'lucide-react',
  ],
};

export default defineConfig({
  plugins: [
    react(),
    ...(process.env.ANALYZE === 'true'
      ? [visualizer({ open: false, filename: 'dist/bundle-report.html', gzipSize: true })]
      : []),
  ],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  build: {
    rollupOptions: {
      output: {
        // Rolldown (Vite 8) rejects the object form of manualChunks but still
        // accepts a function, so the original package lists are kept verbatim
        // and looked up here. advancedChunks was the other option, but its
        // regex groups did not claim the same modules -- rrule fell out into
        // its own chunk and react-dom left react-vendor.
        manualChunks(id: string) {
          for (const [chunk, packages] of Object.entries(MANUAL_CHUNKS)) {
            if (packages.some((pkg) => id.includes(`/node_modules/${pkg}/`))) {
              return chunk;
            }
          }
          return undefined;
        },
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://nginx:80',
        changeOrigin: true,
      },
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    coverage: {
      reporter: ['text', 'html'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/test/**', 'src/**/*.d.ts'],
    },
  },
});
