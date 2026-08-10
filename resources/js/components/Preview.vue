<script setup lang="ts">
import type { Ref } from 'vue'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import Markdown from '~/components/Markdown.vue'
import { isSafeFrameUrl } from '~/lib/sanitize'
import { buildPreviewUrl, resolveLocaleSegments } from '~/lib/preview-url'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { PulseDot } from '~/components/ui/pulse-dot'
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { CommentResource } from '~/types/comments'
import { ContentResource } from '~/types/contents'
import type {
  CommentClickEvent,
  CommentCreateEvent,
  CommentUpdateEvent,
  FieldUpdateEvent,
} from '~/utils/preview-bridge'
import { PreviewBridge } from '~/utils/preview-bridge'

const { t } = useI18n()
const props = defineProps<{
  spaceId: string
  contentId: string
  fullSlug?: string
  updatedAt?: string
  content?: Record<string, unknown>
  itemId?: string | null // Currently selected item in the editor
  comments?: CommentResource[] // Comments for the preview
}>()

const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(props.spaceId) as { data: Ref<SpaceResource | null> }
const { settings } = useSpaceSettings(props.spaceId)

const emit = defineEmits<{
  (e: 'selectItem', itemId: string | null): void
  (e: 'updateField', payload: FieldUpdateEvent): void
  (e: 'commentClick', payload: CommentClickEvent): void
  (e: 'commentCreate', payload: CommentCreateEvent): void
  (e: 'commentUpdate', payload: CommentUpdateEvent): void
}>()

const iframeRef = ref<HTMLIFrameElement | null>(null)
const iframeKey = ref<string>()
const loading = ref<boolean>(true)
const isConnected = ref<boolean>(false)
const mode = ref<'desktop' | 'mobile'>('desktop')
const mobileWidth = ref<number>(384)
const isResizing = ref<boolean>(false)
// Named apart from the `content` prop, which carries the unsaved draft.
const injectedContent = inject<Ref<ContentResource | null>>('content', ref(null))
const previewBridge = shallowRef<PreviewBridge | null>(null)
let readyFallbackTimer: ReturnType<typeof setTimeout> | undefined

const effectiveEnvironment = computed<SpaceEnvironment | null>(() => {
  const userEnv = settings.value.content.environment as SpaceEnvironment | null
  if (userEnv) return userEnv

  const defaultName = currentSpace.value?.settings.default_environment
  if (!defaultName) return null

  return currentSpace.value?.settings.environments?.find((e) => e.name === defaultName) ?? null
})

const availableSegments = computed<string[]>(() => {
  const languageIso = injectedContent.value?.language_iso
  if (!languageIso) return []

  return resolveLocaleSegments(languageIso, currentSpace.value?.settings)
})

const activeSegment = computed<string>(() => {
  const preferred = settings.value.content.siteLocale
  if (preferred !== null && availableSegments.value.includes(preferred)) {
    return preferred
  }

  return availableSegments.value[0] ?? ''
})

const siteLocaleLabel = (segment: string) => {
  const locale = currentSpace.value?.settings.site_locales?.find((l) => l.segment === segment)
  return locale?.name || `/${segment}`
}

const baseSrc = computed(() => {
  const env = effectiveEnvironment.value
  const currentContent = injectedContent.value

  // env.url is space-configured; reject anything that isn't http(s) so a
  // `javascript:` URL can never end up as the iframe src.
  if (!env?.url || !isSafeFrameUrl(env.url) || !currentContent?.language_iso || !props.fullSlug) {
    return null
  }

  return buildPreviewUrl(
    env.url,
    currentSpace.value?.settings,
    currentContent.language_iso,
    props.fullSlug,
    activeSegment.value
  )
})
const currentEnvironmentUrl = computed(() => effectiveEnvironment.value?.url)
const availableEnvironments = computed(() => currentSpace.value?.settings.environments ?? [])

const src = computed(() => {
  const timestamp = props.updatedAt ? new Date(props.updatedAt).getTime() : Date.now()

  return baseSrc?.value ? `${baseSrc.value}?b10cks_vid=draft&b10cks_rv=${timestamp / 1000}` : null
})

const originOf = (url: string | null | undefined): string | null => {
  if (!url) return null
  try {
    return new URL(url).origin
  } catch {
    return null
  }
}

const teardownBridge = () => {
  clearTimeout(readyFallbackTimer)
  previewBridge.value?.destroy()
  previewBridge.value = null
  isConnected.value = false
}

// One bridge per iframe element: refresh and environment/locale switches
// remount the iframe (via iframeKey), which recreates the bridge with the
// then-current target origin. Stacking a second bridge onto the same iframe
// would duplicate every incoming event.
watch(
  iframeRef,
  (iframe) => {
    teardownBridge()
    if (!iframe) return

    // Trust exactly the origins of the configured preview environments; the
    // active one is where outgoing draft updates are addressed.
    const allowedOrigins = availableEnvironments.value
      .map((environment) => originOf(environment.url))
      .filter((origin): origin is string => origin !== null)

    const bridge = new PreviewBridge(iframe, {
      allowedOrigins,
      targetOrigin: originOf(currentEnvironmentUrl.value) ?? undefined,
    })
    bridge.on('SELECT_UPDATE', ({ selectedItem }) => {
      emit('selectItem', selectedItem)
    })
    bridge.on('FIELD_UPDATE', (payload) => {
      emit('updateField', payload)
    })
    bridge.on('COMMENT_CLICK', (payload) => {
      emit('commentClick', payload)
    })
    bridge.on('COMMENT_CREATE', (payload) => {
      emit('commentCreate', payload)
    })
    bridge.on('COMMENT_UPDATE', (payload) => {
      emit('commentUpdate', payload)
    })
    bridge.onReady(() => {
      isConnected.value = true
    })
    previewBridge.value = bridge
  },
  { flush: 'post' }
)

// The bridge buffers these until the preview announces it is ready and
// replays them after every in-iframe navigation, so sending "too early" here
// is safe.
watchEffect(() => {
  if (props.content) {
    previewBridge.value?.updateContent(props.content)
  }
})

watchEffect(() => {
  if (props.itemId !== undefined) {
    previewBridge.value?.updateSelectedItem(props.itemId)
  }
})

watchEffect(() => {
  if (props.comments) {
    previewBridge.value?.updateComments(props.comments)
  }
})

const switchEnvironment = (env: SpaceEnvironment) => {
  ;(settings.value.content as { environment: SpaceEnvironment | null }).environment = env
  refresh()
}

const switchSiteLocale = (segment: string) => {
  settings.value.content.siteLocale = segment
  refresh()
}

const openExternal = () => {
  if (src.value) {
    window.open(src.value, '_blank')
  }
}

const refresh = () => {
  loading.value = true
  isConnected.value = false
  iframeKey.value = Math.random().toString(36).substring(2, 9)
}

const updateItem = (item: Record<string, unknown>) => {
  previewBridge.value?.updateContent(JSON.parse(JSON.stringify(item)))
}
const updateHover = (itemId: string | null) => {
  previewBridge.value?.updateHover(itemId)
}

const copyLink = () => {
  if (!src.value) {
    return
  }

  navigator.clipboard
    .writeText(src.value)
    .then(() => toast.message(t('notifications.preview.copied') as string))
}

// Expose the refresh method to parent components
defineExpose({
  refresh,
  updateItem,
  updateHover,
})

onBeforeUnmount(teardownBridge)

const handleLoad = () => {
  loading.value = false
  // Site SDKs announce B10CKS_BRIDGE_READY once their listener is attached;
  // older ones never do, so after a grace period start sending anyway.
  clearTimeout(readyFallbackTimer)
  readyFallbackTimer = setTimeout(() => previewBridge.value?.markReady(), 500)
}

const containerRef = ref<HTMLElement | null>(null)

const startResize = () => {
  isResizing.value = true
  document.addEventListener('mousemove', handleGlobalMouseMove)
  document.addEventListener('mouseup', handleGlobalMouseUp)
}

const stopResize = () => {
  isResizing.value = false
  document.removeEventListener('mousemove', handleGlobalMouseMove)
  document.removeEventListener('mouseup', handleGlobalMouseUp)
}

const handleGlobalMouseMove = (event: MouseEvent) => {
  if (!isResizing.value || !containerRef.value || mode.value !== 'mobile') return

  const rect = containerRef.value.getBoundingClientRect()
  const newWidth = event.clientX - rect.left
  const minWidth = 320

  if (newWidth >= minWidth) {
    mobileWidth.value = newWidth
  }
}

const handleGlobalMouseUp = () => {
  stopResize()
}

const handleMouseMove = (event: MouseEvent) => {
  if (!isResizing.value || mode.value !== 'mobile') return

  const outerContainer = event.currentTarget as HTMLElement
  if (!outerContainer) return

  const rect = outerContainer.getBoundingClientRect()
  const newWidth = event.clientX - rect.left
  const minWidth = 280
  const maxWidth = 768

  if (newWidth >= minWidth && newWidth <= maxWidth) {
    mobileWidth.value = newWidth
  }
}
</script>

<template>
  <div class="flex flex-1 grow flex-col bg-elevated">
    <div
      ref="containerRef"
      :class="['flex grow flex-col relative', mode === 'mobile' ? 'mx-auto my-4' : '']"
      :style="mode === 'mobile' ? { width: `${mobileWidth}px` } : {}"
    >
      <div
        :class="[
          'flex flex-col grow bg-secondary',
          mode === 'mobile' ? 'rounded-xl shadow-2xl overflow-clip' : '',
        ]"
      >
        <div
          class="flex h-12 items-center gap-3 rounded-top-x; border-b border-b-border bg-background p-3"
        >
          <Icon
            name="lucide:refresh-cw"
            :class="['shrink-0 cursor-pointer', src || 'invisible', loading && 'animate-spin']"
            @click="refresh"
          />
          <SimpleTooltip :tooltip="$t('labels.preview.liveEdit')">
            <PulseDot
              :variant="isConnected ? 'success' : 'default'"
              :live="isConnected"
              size="sm"
            />
          </SimpleTooltip>
          <p class="truncate text-sm">{{ baseSrc || 'about:blank' }}</p>
          <div class="ml-auto flex items-center gap-3">
            <SimpleTooltip
              class="flex"
              side="bottom"
              :tooltip="$t('labels.preview.openExternal')"
            >
              <button
                :class="['shrink-0 cursor-pointer', src || 'invisible']"
                :aria-label="$t('labels.preview.openExternal')"
                @click="openExternal"
              >
                <Icon name="lucide:external-link" />
              </button>
            </SimpleTooltip>
            <SimpleTooltip
              class="flex"
              side="bottom"
              :tooltip="$t('labels.preview.copyLink')"
            >
              <button
                :class="['shrink-0 cursor-pointer', src || 'invisible']"
                :aria-label="$t('labels.preview.copyLink')"
                @click="copyLink"
              >
                <Icon name="lucide:link" />
              </button>
            </SimpleTooltip>
            <div class="h-6 w-px bg-border" />
            <SimpleTooltip
              class="flex"
              side="bottom"
              :tooltip="$t('labels.preview.mode')"
            >
              <button
                class="shrink-0 cursor-pointer"
                :aria-label="$t('labels.preview.mode')"
                @click="mode === 'desktop' ? (mode = 'mobile') : (mode = 'desktop')"
              >
                <Icon name="lucide:monitor-smartphone" />
              </button>
            </SimpleTooltip>
            <DropdownMenu v-if="availableSegments.length > 1">
              <DropdownMenuTrigger class="flex">
                <SimpleTooltip
                  class="flex items-center gap-1"
                  side="bottom"
                  :tooltip="$t('labels.preview.siteLocale')"
                >
                  <Icon name="lucide:globe" />
                  <span class="text-xs font-semibold">/{{ activeSegment }}</span>
                </SimpleTooltip>
              </DropdownMenuTrigger>
              <DropdownMenuContent>
                <DropdownMenuRadioGroup :model-value="activeSegment">
                  <DropdownMenuRadioItem
                    v-for="segment in availableSegments"
                    :key="segment"
                    :value="segment"
                    class="grid"
                    @select="switchSiteLocale(segment)"
                  >
                    <span class="font-semibold text-primary">{{ siteLocaleLabel(segment) }}</span>
                    <span class="text-xs text-primary/60">/{{ segment }}</span>
                  </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
              </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
              <DropdownMenuTrigger
                class="flex"
                :aria-label="$t('labels.preview.settings')"
              >
                <Icon name="lucide:cog" />
              </DropdownMenuTrigger>
              <DropdownMenuContent>
                <DropdownMenuRadioGroup :model-value="currentEnvironmentUrl">
                  <DropdownMenuRadioItem
                    v-for="env in availableEnvironments"
                    :key="env.url"
                    :value="env.url"
                    class="grid"
                    @select="switchEnvironment(env)"
                  >
                    <span class="font-semibold text-primary">{{ env.name }}</span>
                    <span class="text-xs text-primary/60">{{ env.url }}</span>
                  </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
        <iframe
          v-if="baseSrc && src"
          ref="iframeRef"
          :key="iframeKey"
          :src="src"
          :title="fullSlug"
          sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-top-navigation-by-user-activation"
          class="grow bg-white"
          @load="handleLoad()"
        />
        <div
          v-else
          class="flex grow items-center justify-center"
        >
          <Markdown
            class="mx-auto text-center text-sm text-balance text-muted"
            :content="
              availableEnvironments.length > 0
                ? $t('messages.preview.noContent')
                : $t('messages.preview.noEnvironments', {
                    url: $router.resolve({
                      name: 'space-settings-configuration',
                      params: { space: currentSpace?.id },
                    }).href,
                  })
            "
          />
        </div>
      </div>
      <div
        v-if="mode === 'mobile'"
        class="group absolute -right-1 top-0 bottom-0 w-2 cursor-col-resize py-3"
        @mousedown="startResize"
      >
        <div
          class="w-px h-full ml-1 bg-transparent transition-colors"
          :class="[isResizing ? 'bg-accent!' : 'group-hover:bg-accent']"
        ></div>
      </div>
      <div
        v-if="mode === 'mobile'"
        class="absolute -right-4 bottom-0 translate-x-full whitespace-nowrap pointer-events-none"
      >
        <div
          class="text-xs font-semibold text-muted origin-left"
          :style="{ transform: 'rotate(-90deg)' }"
        >
          {{ Math.floor(mobileWidth) }} px
        </div>
      </div>
    </div>
  </div>
</template>
