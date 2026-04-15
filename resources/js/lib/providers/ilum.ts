import { defineProvider } from '@nuxt/image/runtime'

import { buildIlumUrl, type IlumModifiers } from '~/lib/ilum'

interface IlumProviderModifiers extends IlumModifiers {
  path?: string
}

export default defineProvider({
  getImage(
    src: string,
    { modifiers = {}, baseURL = '' }: { modifiers?: IlumProviderModifiers; baseURL?: string } = {}
  ) {
    return {
      url: buildIlumUrl(src, modifiers, baseURL),
    }
  },
})
