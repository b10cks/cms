import { readonly, ref } from 'vue'

import { runtimeConfig } from '~/lib/runtime-config'

/** Response header naming the server's release (`VersionHeader` middleware). */
export const VERSION_HEADER = 'x-b10cks-version'

const newerRelease = ref<string | null>(null)

/**
 * The release the server runs when it differs from the one this tab was
 * loaded with, else null. The shell embeds the release next to its bundle,
 * so a difference means this tab still runs code from before a deploy.
 */
export const outdatedBy = readonly(newerRelease)

/** Record the release a response came from. */
export function noteServerRelease(
  release: string | null,
  loaded: string = runtimeConfig.public.appVersion
): void {
  if (!release || !loaded || release === loaded) return
  newerRelease.value = release
}
