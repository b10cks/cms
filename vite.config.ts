import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { resolve } from 'node:path'
import AutoImport from 'unplugin-auto-import/vite'
import { defineConfig, loadEnv } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'
import svgLoader from 'vite-svg-loader'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_APP_')
  const apiProxyUrl = env.VITE_APP_API_PROXY_URL || 'http://localhost:8000'

  return {
    plugins: [
      laravel(['resources/js/main.ts']),
      AutoImport({
        imports: [
          'vue',
          'vue-router',
          {
            '~/env': ['useRuntimeConfig'],
          },
        ],
        dts: 'auto-imports.d.ts',
        dirs: ['resources/js/composables', 'resources/js/plugins'],
        vueTemplate: true,
      }),
      vue(),
      tailwindcss(),
      svgLoader({
        svgoConfig: {
          plugins: [
            {
              name: 'preset-default',
              params: {
                overrides: {
                  inlineStyles: {
                    onlyMatchedOnce: false,
                  },
                  removeViewBox: false,
                  convertShapeToPath: false,
                  mergePaths: false,
                },
              },
            },
            {
              name: 'removeAttrs',
              params: {
                attrs: 'data-name',
              },
            },
            {
              name: 'convertStyleToAttrs',
            },
          ],
        },
      }),
      VitePWA({
        registerType: 'autoUpdate',
        workbox: {
          navigateFallback: '/',
          globPatterns: ['**/*.{js,css,html,ico,png,svg}'],
        },
        devOptions: {
          enabled: true,
          type: 'module',
        },
        manifest: {
          name: 'b10cks CMS',
          short_name: 'b10cks',
          description: 'b10cks – the opinionated headless cms',
          theme_color: '#ffffff',
          background_color: '#0B0B0F',
          display: 'standalone',
          start_url: '/',
          icons: [
            {
              src: '/web-app-manifest-192x192.png',
              sizes: '192x192',
              type: 'image/png',
            },
            {
              src: '/web-app-manifest-512x512.png',
              sizes: '512x512',
              type: 'image/png',
            },
          ],
        },
      }),
    ],
    resolve: {
      alias: {
        '~': resolve(__dirname, 'resources/js'),
        '@': resolve(__dirname, 'resources/js'),
      },
    },
    css: {
      devSourcemap: true,
    },
    server: {
      port: 3000,
      https: {
        key: './localhost.key',
        cert: './localhost.crt',
      },
      proxy: {
        '/auth': {
          target: apiProxyUrl,
          changeOrigin: true,
          headers: { 'accept-encoding': 'identity' },
        },
        '/mgmt/v1': {
          target: apiProxyUrl,
          changeOrigin: true,
          headers: { 'accept-encoding': 'identity' },
        },
        '/api/v1': {
          target: apiProxyUrl,
          changeOrigin: true,
          headers: { 'accept-encoding': 'identity' },
        },
      },
    },
    build: {
      assetsDir: '_assets',
      target: 'esnext',
      minify: 'esbuild',
    },
    define: {
      __DEV__: JSON.stringify(process.env.NODE_ENV !== 'production'),
    },
  }
})
