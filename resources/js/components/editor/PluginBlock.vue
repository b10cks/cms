<script setup lang="ts">
import { useDark } from '@vueuse/core'

import { Button } from '~/components/ui/button'
import { useFieldPlugins } from '~/composables/useFieldPlugins'
import { isSafeFrameUrl } from '~/lib/sanitize'
import { PluginBridge } from '~/utils/plugin-bridge'

const props = defineProps<{
  item: PluginSchema & { key: string }
  modelValue?: unknown
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: unknown]
}>()

const { t } = useI18n()
const isDark = useDark()

const { useFieldPluginsQuery } = useFieldPlugins(props.spaceId)
// The handle filter is a LIKE match, so find() still picks the exact handle from the result.
const { data: pluginList, isLoading } = useFieldPluginsQuery(
  computed(() => ({ handle: props.item.plugin_handle }))
)

const plugin = computed(() =>
  pluginList.value?.data.find((p) => p.handle === props.item.plugin_handle)
)

const iframeRef = ref<HTMLIFrameElement | null>(null)
const bridge = shallowRef<PluginBridge | null>(null)
const ready = ref(false)
const timedOut = ref(false)
const isModal = ref(false)
const height = ref(120)
const reloadCount = ref(0)

// Seed the declared manifest height until the plugin reports its own via HEIGHT_CHANGE.
watch(plugin, (p) => {
  if (!ready.value && p?.manifest?.height) {
    height.value = Math.min(1200, Math.max(50, p.manifest.height))
  }
}, { immediate: true })

const READY_TIMEOUT_MS = 8000
let readyTimer: ReturnType<typeof setTimeout> | null = null

// Value the plugin itself just emitted; used to avoid echoing it back.
let lastPluginValue: unknown

const token = crypto.randomUUID()

const frameSrc = computed(() => {
  if (!plugin.value) return null

  const base = plugin.value.dev_mode && plugin.value.dev_url
    ? plugin.value.dev_url
    : plugin.value.sandbox_url

  if (!base || !isSafeFrameUrl(base)) return null

  return `${base}#b10cks-token=${encodeURIComponent(token)}&r=${reloadCount.value}`
})

// Never allow-same-origin: combined with allow-scripts it voids the sandbox,
// and the sandbox shell is served from the admin's own origin — plugin code
// would get window.parent.document, the session cookie and the CSRF token.
// Dev bundles lose HMR as a result; correctness wins over convenience here.
const sandboxAttr = 'allow-scripts allow-forms allow-popups allow-modals'

const mergedOptions = computed<Record<string, string>>(() => {
  const defaults: Record<string, string> = {}
  for (const option of plugin.value?.manifest?.options ?? []) {
    if (option.default != null) defaults[option.key] = option.default
  }
  return { ...defaults, ...props.item.options }
})

const sendInit = () => {
  bridge.value?.init({
    value: props.modelValue ?? null,
    options: mergedOptions.value,
    context: {
      spaceId: props.spaceId,
      fieldKey: props.item.key,
      readOnly: Boolean(props.readOnly),
      isModal: isModal.value,
    },
    theme: isDark.value ? 'dark' : 'light',
  })
}

const setupBridge = () => {
  if (!iframeRef.value) return

  bridge.value?.destroy()
  ready.value = false
  timedOut.value = false

  const b = new PluginBridge(iframeRef.value, token)
  bridge.value = b

  b.on('PLUGIN_READY', () => {
    ready.value = true
    timedOut.value = false
    if (readyTimer) clearTimeout(readyTimer)
    sendInit()
  })
  b.on('VALUE_CHANGE', ({ value }) => {
    lastPluginValue = value
    emit('update:modelValue', value)
  })
  b.on('HEIGHT_CHANGE', ({ height: next }) => {
    height.value = Math.min(1200, Math.max(50, Math.round(next)))
  })
  b.on('MODAL_TOGGLE', ({ open }) => {
    isModal.value = Boolean(open)
  })
  b.on('ASSET_SELECT_REQUEST', ({ requestId }) => {
    b.post('ASSET_SELECT_RESULT', { requestId, asset: null, error: 'unsupported' })
  })

  if (readyTimer) clearTimeout(readyTimer)
  readyTimer = setTimeout(() => {
    if (!ready.value) timedOut.value = true
  }, READY_TIMEOUT_MS)
}

// The :key remount updates iframeRef and frameSrc, so the watcher below re-runs setupBridge.
const retry = () => {
  reloadCount.value += 1
}

watch([iframeRef, frameSrc], () => {
  if (iframeRef.value && frameSrc.value) setupBridge()
})

watch(
  () => props.modelValue,
  (value) => {
    if (!ready.value || value === lastPluginValue) return
    bridge.value?.updateValue(value ?? null)
  }
)

watch(
  () => props.readOnly,
  (readOnly) => bridge.value?.updateReadOnly(Boolean(readOnly))
)

watch(isDark, (dark) => bridge.value?.updateTheme(dark ? 'dark' : 'light'))

onBeforeUnmount(() => {
  if (readyTimer) clearTimeout(readyTimer)
  bridge.value?.destroy()
})
</script>

<template>
  <div>
    <div
      v-if="isLoading"
      class="h-20 animate-pulse rounded-md bg-gray-200"
    />
    <div
      v-else-if="!plugin"
      class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
    >
      {{ t('labels.fieldPlugins.editor.pluginNotFound', { handle: item.plugin_handle }) }}
    </div>
    <div
      v-else-if="!plugin.is_active"
      class="rounded-md border border-input bg-muted p-3 text-sm text-muted-foreground"
    >
      {{ t('labels.fieldPlugins.editor.pluginDeactivated', { name: plugin.name }) }}
    </div>
    <div
      v-else-if="!frameSrc"
      class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-700"
    >
      {{ t('labels.fieldPlugins.editor.pluginNotPublished', { name: plugin.name }) }}
    </div>
    <template v-else>
      <div
        v-if="timedOut"
        class="flex items-center justify-between rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
      >
        <span>{{ t('labels.fieldPlugins.editor.pluginLoadFailed', { name: plugin.name }) }}</span>
        <Button
          variant="outline"
          size="sm"
          @click="retry"
        >
          {{ t('labels.fieldPlugins.editor.retry') }}
        </Button>
      </div>
      <!-- The modal expand keeps the same iframe node; re-parenting would reload it. -->
      <div
        v-show="!timedOut"
        :class="isModal
          ? 'fixed inset-0 z-50 flex flex-col bg-black/50 p-6'
          : ''"
      >
        <iframe
          :key="reloadCount"
          ref="iframeRef"
          :src="frameSrc"
          :sandbox="sandboxAttr"
          referrerpolicy="no-referrer"
          class="w-full rounded-md border border-input bg-background"
          :class="isModal ? 'h-full flex-1' : ''"
          :style="isModal ? undefined : { height: `${height}px` }"
          :title="plugin.name"
        />
      </div>
    </template>
  </div>
</template>
