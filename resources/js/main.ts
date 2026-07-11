import { icons as flagIcons } from '@iconify-json/flag'
import { icons as lucideIcons } from '@iconify-json/lucide'
import { addCollection } from '@iconify/vue'
import { createApp } from 'vue'

import App from '~/app.vue'

import '~/assets/css/main.css'

// Register the bundled icon sets up front so `lucide:`/`flag:` icons render
// synchronously and work offline (PWA). Only `b10cks:` space icons still hit the
// runtime Iconify API path in Icon.vue.
addCollection(lucideIcons)
addCollection(flagIcons)
import { isClient } from '~/lib/env'
import { installEcho } from '~/plugins/echo'
import { installI18n } from '~/plugins/i18n'
import { installPosthog } from '~/plugins/posthog'
import { installVueQuery } from '~/plugins/vue-query'
import { router } from '~/router'

const app = createApp(App)

installI18n(app)
installVueQuery(app)

app.use(router)

app.mount('#app')

if (isClient) {
  installEcho(app)
  installPosthog()
}
