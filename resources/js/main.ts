import { icons as lucideIcons } from '@iconify-json/lucide'
import { addCollection } from '@iconify/vue'
import { createApp } from 'vue'

import App from '~/app.vue'
// Only the framework logos the onboarding guide offers, extracted from
// @iconify-json/simple-icons. See lib/brand-icons.json.
import brandIcons from '~/lib/brand-icons.json'
// Only the handful of `flag:` icons we actually use, extracted from
// @iconify-json/flag (whose full collection is ~3.9MB). See lib/flag-icons.json.
import flagIcons from '~/lib/flag-icons.json'

import '~/assets/css/main.css'

// Register the bundled icon sets up front so `lucide:`/`flag:`/`brand:` icons
// render synchronously and work offline (PWA). Only `b10cks:` space icons still
// hit the runtime Iconify API path in Icon.vue.
addCollection(lucideIcons)
addCollection(flagIcons)
addCollection(brandIcons)
import { isClient } from '~/lib/env'
import { installAuthHandler } from '~/plugins/auth'
import { installEcho } from '~/plugins/echo'
import { installI18n } from '~/plugins/i18n'
import { installPosthog } from '~/plugins/posthog'
import { installVueQuery } from '~/plugins/vue-query'
import { router } from '~/router'

const app = createApp(App)

installI18n(app)
installVueQuery(app)

// Must run before the router mounts: the first navigation guard already hits the
// API, and a 401 there needs a handler to redirect instead of failing silently.
installAuthHandler()

app.use(router)

app.mount('#app')

if (isClient) {
  installEcho(app)
  installPosthog()
}
