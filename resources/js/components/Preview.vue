<script setup lang="ts">
import type { Ref } from 'vue'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import Markdown from '~/components/Markdown.vue'
import { isSafeFrameUrl } from '~/lib/sanitize'
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
const content = inject<Ref<ContentResource | null>>('content', ref(null))
let previewBridge: PreviewBridge

const effectiveEnvironment = computed<SpaceEnvironment | null>(() => {
  const userEnv = settings.value.content.environment as SpaceEnvironment | null
  if (userEnv) return userEnv

  const defaultName = currentSpace.value?.settings.default_environment
  if (!defaultName) return null

  return currentSpace.value?.settings.environments?.find((e) => e.name === defaultName) ?? null
})

const baseSrc = computed(() => {
  const env = effectiveEnvironment.value
  const currentContent = content.value

  // env.url is space-configured; reject anything that isn't http(s) so a
  // `javascript:` URL can never end up as the iframe src.
  if (!env?.url || !isSafeFrameUrl(env.url) || !currentContent?.language_iso || !props.fullSlug) {
    return null
  }

  const slugStrategy = currentSpace.value?.settings.slug_strategy
  const needsPrepend =
    slugStrategy === 'always_prepend' ||
    (slugStrategy === 'prepend_translations' &&
      currentContent.language_iso !== currentSpace.value?.settings.default_language)
  const prefix = needsPrepend ? `/${currentContent.language_iso}` : ''

  const url = env.url.replace(/\/$/, '')
  return `${url}${prefix}${props.fullSlug}`
})
const currentEnvironmentUrl = computed(() => effectiveEnvironment.value?.url)
const availableEnvironments = computed(() => currentSpace.value?.settings.environments ?? [])

const src = computed(() => {
  const timestamp = props.updatedAt ? new Date(props.updatedAt).getTime() : Date.now()

  return baseSrc?.value ? `${baseSrc.value}?b10cks_vid=draft&b10cks_rv=${timestamp / 1000}` : null
})

// Initialize connection with the iframe
const setupConnection = async () => {
  if (!iframeRef.value || !baseSrc.value) return

  previewBridge = new PreviewBridge(iframeRef.value)
  previewBridge.on('SELECT_UPDATE', ({ selectedItem }) => {
    emit('selectItem', selectedItem)
  })
  previewBridge.on('FIELD_UPDATE', (payload) => {
    emit('updateField', payload)
  })
  previewBridge.on('COMMENT_CLICK', (payload) => {
    emit('commentClick', payload)
  })
  previewBridge.on('COMMENT_CREATE', (payload) => {
    emit('commentCreate', payload)
  })
  previewBridge.on('COMMENT_UPDATE', (payload) => {
    emit('commentUpdate', payload)
  })
  isConnected.value = true
}

onMounted(() => {
  if (!loading.value) {
    setupConnection()
  }
})

watch(loading, (isLoading) => {
  if (!isLoading) {
    setupConnection()
  }
})

watchEffect(() => {
  if (isConnected.value && props.content) {
    previewBridge.updateContent(props.content)
  }
})

watchEffect(() => {
  if (isConnected.value && props.itemId !== undefined) {
    previewBridge.updateSelectedItem(props.itemId)
  }
})

watchEffect(() => {
  if (isConnected.value && props.comments) {
    previewBridge.updateComments(props.comments)
  }
})

const switchEnvironment = (env: SpaceEnvironment) => {
  loading.value = true
  ;(settings.value.content as { environment: SpaceEnvironment | null }).environment = env
  isConnected.value = false
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
  if (previewBridge) {
    previewBridge.updateContent(JSON.parse(JSON.stringify(item)))
  }
}
const updateHover = (itemId: string | null) => {
  if (previewBridge) {
    previewBridge.updateHover(itemId)
  }
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

onBeforeUnmount(() => previewBridge && previewBridge.destroy())

const handleLoad = () => {
  loading.value = false
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
                @click="mode === 'desktop' ? (mode = 'mobile') : (mode = 'desktop')"
              >
                <Icon name="lucide:monitor-smartphone" />
              </button>
            </SimpleTooltip>
            <DropdownMenu>
              <DropdownMenuTrigger class="flex">
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
