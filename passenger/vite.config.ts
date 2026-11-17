import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/bluemap': {
        target: 'http://127.0.0.1:8100',
        rewrite: (path) => path.replace(/^\/bluemap/, ''),
        headers: { cookie: '' },
      },
      '/api': 'http://127.0.0.1:8787',
      '/ws': { target: 'ws://127.0.0.1:8790', ws: true },
    },
  },
});
