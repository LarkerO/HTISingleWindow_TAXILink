import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({
  plugins: [react()],
  optimizeDeps: {
    // Pre-bundle lazy ProComponents fields before the first page opens.
    noDiscovery: true,
    include: [
      'react',
      'react/jsx-runtime',
      'react/jsx-dev-runtime',
      'react-dom',
      'react-dom/client',
      'antd',
      'antd/locale/zh_CN',
      '@ant-design/icons',
      '@ant-design/pro-components',
    ],
  },
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
