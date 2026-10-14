import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In development the Symfony API runs on :8010; Vite proxies /api to it, so the
// browser sees one origin and no CORS setup is needed.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8010',
    },
  },
  build: {
    rolldownOptions: {
      output: {
        // Libraries change less often than the app: separate chunks stay cached across deploys.
        codeSplitting: {
          groups: [
            { name: 'react', test: /node_modules[\\/](react|react-dom|react-router|scheduler)[\\/]/ },
            { name: 'motion', test: /node_modules[\\/](motion|motion-dom|motion-utils|framer-motion)[\\/]/ },
            { name: 'query', test: /node_modules[\\/]@tanstack[\\/]/ },
          ],
        },
      },
    },
  },
})
