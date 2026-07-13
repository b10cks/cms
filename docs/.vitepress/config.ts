import type { DefaultTheme, LocaleConfig, UserConfig } from 'vitepress'
import { defineConfig } from 'vitepress'

/**
 * Navigation and sidebar are built per locale so additional languages only
 * need a new entry in `locales` below plus a translated copy of the pages
 * under `docs/<locale>/` (e.g. `docs/de/concepts/spaces.md`).
 */
function nav(prefix = ''): DefaultTheme.NavItem[] {
  return [
    { text: 'Guide', link: `${prefix}/getting-started/introduction`, activeMatch: `^${prefix}/(getting-started|guides)/` },
    { text: 'Concepts', link: `${prefix}/concepts/spaces`, activeMatch: `^${prefix}/concepts/` },
    { text: 'User Guide', link: `${prefix}/ui/dashboard`, activeMatch: `^${prefix}/ui/` },
    { text: 'API', link: `${prefix}/api/overview`, activeMatch: `^${prefix}/api/` },
  ]
}

function sorted(items: DefaultTheme.SidebarItem[]): DefaultTheme.SidebarItem[] {
  return items.toSorted((a, b) => (a.text ?? '').localeCompare(b.text ?? ''))
}

function guideSidebar(prefix = ''): DefaultTheme.SidebarItem[] {
  return [
    {
      text: 'Getting Started',
      items: sorted([
        { text: 'Introduction', link: `${prefix}/getting-started/introduction` },
        { text: 'Quickstart', link: `${prefix}/getting-started/quickstart` },
        { text: 'Self-hosting', link: `${prefix}/getting-started/self-hosting` },
      ]),
    },
    {
      text: 'Frameworks',
      items: sorted([
        { text: 'Nuxt', link: `${prefix}/guides/nuxt` },
        { text: 'Vue', link: `${prefix}/guides/vue` },
        { text: 'React', link: `${prefix}/guides/react` },
        { text: 'Next.js', link: `${prefix}/guides/nextjs` },
        { text: 'Svelte', link: `${prefix}/guides/svelte` },
        { text: 'JavaScript', link: `${prefix}/guides/javascript` },
      ]),
    },
    {
      text: 'Topics',
      items: sorted([
        { text: 'Querying Content', link: `${prefix}/guides/querying-content` },
        { text: 'Rendering Rich Text', link: `${prefix}/guides/rich-text` },
        { text: 'Live Preview & Visual Editing', link: `${prefix}/guides/live-preview` },
      ]),
    },
    {
      text: 'Tooling',
      items: sorted([
        { text: 'CLI', link: `${prefix}/guides/cli` },
        { text: 'Management Client', link: `${prefix}/guides/management-client` },
        { text: 'MCP Server (AI Assistants)', link: `${prefix}/guides/mcp-server` },
      ]),
    },
  ]
}

function conceptsSidebar(prefix = ''): DefaultTheme.SidebarItem[] {
  return [
    {
      text: 'Concepts',
      items: sorted([
        { text: 'Spaces', link: `${prefix}/concepts/spaces` },
        { text: 'Blocks', link: `${prefix}/concepts/blocks` },
        { text: 'Fields', link: `${prefix}/concepts/fields` },
        { text: 'Content', link: `${prefix}/concepts/content` },
        { text: 'Versions & Publishing', link: `${prefix}/concepts/versions-and-publishing` },
        { text: 'Releases', link: `${prefix}/concepts/releases` },
        { text: 'Internationalization', link: `${prefix}/concepts/internationalization` },
        { text: 'Assets', link: `${prefix}/concepts/assets` },
        { text: 'Image Service (Ilum)', link: `${prefix}/concepts/image-service` },
        { text: 'Data Sources', link: `${prefix}/concepts/data-sources` },
        { text: 'Redirects', link: `${prefix}/concepts/redirects` },
        { text: 'Icons', link: `${prefix}/concepts/icons` },
        { text: 'Automations & Webhooks', link: `${prefix}/concepts/automations` },
        { text: 'Access Tokens & Caching', link: `${prefix}/concepts/access-tokens` },
      ]),
    },
  ]
}

function uiSidebar(prefix = ''): DefaultTheme.SidebarItem[] {
  return [
    {
      text: 'Using the App',
      items: sorted([
        { text: 'Dashboard & Navigation', link: `${prefix}/ui/dashboard` },
        { text: 'Content', link: `${prefix}/ui/content` },
        { text: 'Visual Editor (Canvas)', link: `${prefix}/ui/canvas` },
        { text: 'Block Library', link: `${prefix}/ui/blocks` },
        { text: 'Asset Manager', link: `${prefix}/ui/assets` },
        { text: 'Data Sources', link: `${prefix}/ui/data-sources` },
        { text: 'Icons', link: `${prefix}/ui/icons` },
        { text: 'Redirects', link: `${prefix}/ui/redirects` },
        { text: 'Releases', link: `${prefix}/ui/releases` },
        { text: 'Automations', link: `${prefix}/ui/automations` },
        { text: 'Audit Logs', link: `${prefix}/ui/audit-logs` },
        { text: 'Space Settings', link: `${prefix}/ui/settings` },
        { text: 'Account, Teams & Spaces', link: `${prefix}/ui/account` },
      ]),
    },
  ]
}

function apiSidebar(prefix = ''): DefaultTheme.SidebarItem[] {
  return [
    {
      text: 'API',
      items: sorted([
        { text: 'Overview', link: `${prefix}/api/overview` },
        { text: 'Data API', link: `${prefix}/api/data-api` },
        { text: 'Management API', link: `${prefix}/api/management-api` },
        { text: 'OpenAPI Specs', link: `${prefix}/api/openapi` },
      ]),
    },
    {
      text: 'Reference',
      items: sorted([
        { text: 'Data API', link: `${prefix}/api/reference/data-api` },
        { text: 'Management API', link: `${prefix}/api/reference/management-api` },
        { text: 'Auth API', link: `${prefix}/api/reference/auth-api` },
      ]),
    },
  ]
}

/**
 * Multi-sidebar: the left menu only shows the section the current page
 * belongs to; switching sections happens through the top nav.
 */
function sidebar(prefix = ''): DefaultTheme.SidebarMulti {
  const guide = guideSidebar(prefix)

  return {
    [`${prefix}/getting-started/`]: guide,
    [`${prefix}/guides/`]: guide,
    [`${prefix}/concepts/`]: conceptsSidebar(prefix),
    [`${prefix}/ui/`]: uiSidebar(prefix),
    [`${prefix}/api/`]: apiSidebar(prefix),
  }
}

const locales: LocaleConfig<DefaultTheme.Config> = {
  root: {
    label: 'English',
    lang: 'en',
    themeConfig: {
      nav: nav(),
      sidebar: sidebar(),
    },
  },
  // Add further languages like this (pages live under docs/de/…):
  // de: {
  //   label: 'Deutsch',
  //   lang: 'de',
  //   link: '/de/',
  //   themeConfig: { nav: nav('/de'), sidebar: sidebar('/de') },
  // },
}

/** Canonical public URL of the docs (see `config/app.php`). */
const DOCS_HOST = 'https://www.b10cks.com'

/** App URL used for the login/signup links in the navbar. */
const APP_URL = (process.env.APP_URL ?? 'https://app.b10cks.com').replace(/\/+$/, '')

export default defineConfig({
  title: 'b10cks',
  description: 'Documentation for b10cks – the opinionated headless CMS',
  base: '/docs/',
  outDir: '../public/docs',
  cacheDir: './.vitepress/cache',
  srcExclude: ['README.md'],
  cleanUrls: true,
  lastUpdated: true,
  metaChunk: true,

  sitemap: {
    hostname: `${DOCS_HOST}/docs/`,
  },

  head: [
    // Reuse the app's favicons from the site root (public/), so docs and app stay in sync
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    ['link', { rel: 'icon', type: 'image/png', sizes: '96x96', href: '/favicon-96x96.png' }],
    ['link', { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' }],
    ['meta', { name: 'theme-color', content: '#0B0B0F' }],
    ['meta', { property: 'og:site_name', content: 'b10cks Docs' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:image', content: `${DOCS_HOST}/docs/b10cks.png` }],
    ['meta', { name: 'twitter:card', content: 'summary' }],
  ],

  // Canonical URL + per-page Open Graph tags
  transformPageData(pageData) {
    const cleanPath = pageData.relativePath.replace(/((^|\/)index)?\.md$/, '')
    const canonical = `${DOCS_HOST}/docs/${cleanPath}`

    pageData.frontmatter.head ??= []
    pageData.frontmatter.head.push(
      ['link', { rel: 'canonical', href: canonical }],
      ['meta', { property: 'og:url', content: canonical }],
      ['meta', { property: 'og:title', content: pageData.title ? `${pageData.title} | b10cks Docs` : 'b10cks Docs' }],
      ['meta', { property: 'og:description', content: pageData.description || 'Documentation for b10cks – the opinionated headless CMS' }],
    )
  },

  locales,

  themeConfig: {
    logo: { light: '/logo.svg', dark: '/logo-dark.svg' },
    search: {
      provider: 'local',
    },
    editLink: {
      pattern: 'https://github.com/b10cks/cms/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
    lastUpdated: {
      text: 'Last updated',
    },
    socialLinks: [{ icon: 'github', link: 'https://github.com/b10cks/cms' }],
    outline: { level: [2, 3] },
    externalLinkIcon: true,
    footer: {
      message: 'Released under the AGPL-3.0 License.',
      copyright: 'Copyright © b10cks',
    },
  },

  vite: {
    // Keep the docs build fully independent from the app's vite.config.ts
    configFile: false,
    define: {
      __APP_URL__: JSON.stringify(APP_URL),
    },
  },
} satisfies UserConfig<DefaultTheme.Config>)
