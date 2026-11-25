import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      // API corporativa de usuarios Dimerc (ApiUserService)
      '/api/usuarios_data': {
        target: 'https://apigw.dimerc.cl:8443',
        changeOrigin: true,
        secure: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
    },
    cors: {
      origin: [
        'http://localhost:5173',
        'http://127.0.0.1:5173'
      ],
      credentials: true,
      methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
      allowedHeaders: ['Content-Type', 'Authorization', 'Accept']
    }
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom', 'react-router-dom'],
          azure: ['@azure/msal-browser', '@azure/msal-react', '@microsoft/microsoft-graph-client'],
          antd: ['antd', '@ant-design/icons']
        }
      }
    }
  }
})
