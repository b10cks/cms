<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { toast } from 'vue-sonner'
import Markdown from '~/components/Markdown.vue'
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
  content?: never // Content for live updates
  itemId?: string | null // Currently selected item in the editor
  comments?: CommentResource[] // Comments for the preview
}>()

const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(props.spaceId)
const { settings } = useSpaceSettings(props.spaceId)

const emit = defineEmits<{
  (e: 'selectItem', itemId: string): void
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
let previewBridge: PreviewBridge

const baseSrc = computed(() => {
  const env = settings.value.content.environment
  if (!env?.url) return null

  const url = env.url.replace(/\/$/, '')
  return `${url}${props.fullSlug}`
})

const src = computed(() => {
  const timestamp = props.updatedAt ? new Date(props.updatedAt).getTime() : Date.now()

  return baseSrc?.value ? `${baseSrc.value}?b10cks_vid=draft&b10cks_rv=${timestamp}` : null
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
  settings.value.content.environment = env
  isConnected.value = false
}

const openExternal = () => {
  window.open(src.value, '_blank')
}

const refresh = () => {
  loading.value = true
  isConnected.value = false
  iframeKey.value = Math.random().toString(36).substring(2, 9)
}

const updateItem = (item: never) => {
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
  navigator.clipboard.writeText(src.value).then(() => toast.message(t('notifications.preview.copied') as string))
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
</script>

<template>
  <div class="flex w-full grow flex-col bg-elevated">
    <div
      :class="[
        'flex grow flex-col bg-secondary',
        mode === 'mobile' ? 'mx-auto my-4 w-sm overflow-hidden rounded-xl shadow-2xl' : 'w-full',
      ]"
    >
      <div class="flex h-12 w-full items-center gap-3 border-b border-b-input bg-background p-3">
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
          <icon
            name="lucide:external-link"
            :class="['shrink-0 cursor-pointer', src || 'invisible']"
            @click="openExternal"
          />
          <icon
            name="lucide:link"
            :class="['shrink-0 cursor-pointer', src || 'invisible']"
            @click="copyLink"
          />
          <div class="h-6 w-px bg-elevated" />
          <Icon
            name="lucide:monitor-smartphone"
            class="shrink-0 cursor-pointer"
            @click="mode === 'desktop' ? (mode = 'mobile') : (mode = 'desktop')"
          />
          <DropdownMenu>
            <DropdownMenuTrigger class="flex">
              <Icon
                name="lucide:cog"
                class="cursor-pointer"
              />
            </DropdownMenuTrigger>
            <DropdownMenuContent>
              <DropdownMenuRadioGroup :model-value="settings.content.environment?.url">
                <DropdownMenuRadioItem
                  v-for="env in currentSpace?.settings.environments"
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
        v-if="baseSrc"
        ref="iframeRef"
        :key="iframeKey"
        :src="src"
        :title="fullSlug"
        class="w-full grow bg-white"
        @load="handleLoad()"
      />
      <div
        v-else
        class="flex grow items-center justify-center"
      >
        <Markdown
          class="mx-auto text-center text-sm text-balance text-muted"
          :content="
            currentSpace?.settings.environments?.length > 0
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
  </div>
</template>
