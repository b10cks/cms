import type { Theme } from 'vitepress'
import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'
import { theme as openapiTheme } from 'vitepress-openapi/client'
import 'vitepress-openapi/dist/style.css'
import './custom.css'
import NavCta from './NavCta.vue'

export default {
  extends: DefaultTheme,
  Layout: () =>
    h(DefaultTheme.Layout, null, {
      'nav-bar-content-after': () => h(NavCta),
      'nav-screen-content-after': () => h(NavCta, { screen: true }),
    }),
  async enhanceApp(ctx) {
    openapiTheme.enhanceApp(ctx)
  },
} satisfies Theme
