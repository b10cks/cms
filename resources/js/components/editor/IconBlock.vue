<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { RouterLink } from 'vue-router'

import Icon from '~/components/Icon.vue'
import IconGrid from '~/components/icons/IconGrid.vue'
import IconifyPicker from '~/components/icons/IconifyPicker.vue'
import IconPreview from '~/components/icons/IconPreview.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Label } from '~/components/ui/form'
import { Spinner } from '~/components/ui/spinner'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { splitIconName } from '~/lib/iconify'
import { api } from '~/api'

const props = defineProps<{
  item: IconSchema & { key: string }
  modelValue?: string | null
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const { t } = useI18n()

const localValue = ref<string | null>(null)
watch(
  () => props.modelValue,
  (value) => {
    localValue.value = value ?? null
  },
  { immediate: true }
)

const hasValue = computed(() => !!localValue.value)

const allowedCollections = computed(() => (props.item.allowed_collections ?? []).filter(Boolean))

// An Iconify tab is only worth showing when the field actually resolves to a library:
// `collections` with an empty allow-list would otherwise silently browse all of Iconify.
const hasIconify = computed(
  () =>
    props.item.source === 'all' ||
    (props.item.source === 'collections' && allowedCollections.value.length > 0)
)
const iconifySource = computed<'all' | 'collections'>(() =>
  props.item.source === 'collections' ? 'collections' : 'all'
)

// One-row probe telling us whether this space uploaded any icons of its own. The query key is
// shared, so a form with many icon fields still issues a single request.
const { useIconsQuery } = useIcons(computed(() => props.spaceId))
const registryProbe = useIconsQuery(() => ({ per_page: 1 }))
const hasRegistry = computed(() => (registryProbe.data.value?.meta.total ?? 0) > 0)
const sourcesPending = computed(() => registryProbe.isPending.value)
const hasBothSources = computed(() => hasRegistry.value && hasIconify.value)
const hasAnySource = computed(() => hasRegistry.value || hasIconify.value)
const sourceKnownEmpty = computed(() => !sourcesPending.value && !hasAnySource.value)

const isRegistry = computed(() => localValue.value?.startsWith('b10cks:') ?? false)
const registryKey = computed(() =>
  isRegistry.value ? localValue.value!.slice('b10cks:'.length) : null
)

// Load registry icon body on-demand for display (not stored in content)
const registryIconQuery = useQuery({
  queryKey: computed(() => queryKeys.icons(props.spaceId).list({ key: registryKey.value })),
  queryFn: async () => {
    const response = await api.forSpace(props.spaceId).icons.index({
      sort: '+key',
      key: registryKey.value!,
      per_page: 1,
    })
    return response.data[0] ?? null
  },
  enabled: isRegistry,
})
const registryIcon = computed<IconResource | null>(() => registryIconQuery.data.value ?? null)

const pickerOpen = ref(false)
const activeTab = ref<'registry' | 'iconify'>('registry')

watchEffect(() => {
  if (activeTab.value === 'registry' && !hasRegistry.value && hasIconify.value) {
    activeTab.value = 'iconify'
  } else if (activeTab.value === 'iconify' && !hasIconify.value && hasRegistry.value) {
    activeTab.value = 'registry'
  }
})

const setValue = (value: string | null) => {
  localValue.value = value
  emit('update:modelValue', value)
}

const handleRegistrySelect = (icon: IconResource) => {
  setValue(`b10cks:${icon.key}`)
  pickerOpen.value = false
}

const handleIconifySelect = (iconName: string) => {
  setValue(iconName)
  pickerOpen.value = false
}

const displayName = computed(() => {
  if (!localValue.value) return ''
  if (isRegistry.value && registryIcon.value) return registryIcon.value.name
  return splitIconName(localValue.value).name.replace(/-/g, ' ')
})

const clear = () => setValue(null)

const openPicker = () => {
  if (props.readOnly) return
  activeTab.value = hasRegistry.value ? 'registry' : 'iconify'
  pickerOpen.value = true
}
</script>

<template>
  <div class="grid gap-2">
    <Label
      :label="item.name || item.key"
      :required="item.required"
    />
    <div
      v-if="!hasValue"
      role="button"
      :tabindex="readOnly ? -1 : 0"
      :aria-label="t('labels.icons.field.add')"
      :class="[
        'rounded-lg border-1 border-input bg-surface p-6 text-center transition-colors',
        readOnly ? 'cursor-default' : 'cursor-pointer hover:border-muted',
      ]"
      @click="openPicker"
      @keydown.enter.prevent="openPicker"
      @keydown.space.prevent="openPicker"
    >
      <Icon
        name="lucide:shapes"
        size="24"
        class="mx-auto mb-3 text-muted"
      />
      <p class="mb-1 text-sm font-semibold text-primary">
        {{ sourceKnownEmpty ? t('labels.icons.field.emptyTitle') : t('labels.icons.field.add') }}
      </p>
      <p class="text-xs text-muted">
        {{ sourceKnownEmpty ? t('labels.icons.field.emptyHint') : t('labels.icons.field.addHint') }}
      </p>
    </div>
    <div
      v-else-if="localValue"
      class="group relative flex items-center gap-3 overflow-hidden rounded-lg border border-input bg-surface p-2"
    >
      <div class="flex size-12 shrink-0 items-center justify-center rounded border border-input bg-background text-primary">
        <IconPreview
          v-if="isRegistry && registryIcon"
          :body="registryIcon.body"
          :width="registryIcon.width"
          :height="registryIcon.height"
          size="28"
        />
        <Icon
          v-else-if="!isRegistry"
          :name="localValue"
          size="28"
        />
      </div>
      <div class="min-w-0 flex-1">
        <p class="truncate font-semibold text-primary">{{ displayName }}</p>
        <p class="truncate text-sm text-muted">{{ localValue }}</p>
      </div>
      <div class="ml-auto flex items-center gap-2 opacity-0 group-hover:opacity-100">
        <button
          v-if="!readOnly"
          type="button"
          class="flex cursor-pointer items-center hover:text-primary"
          :aria-label="t('actions.icons.replace')"
          :title="t('actions.icons.replace')"
          @click="openPicker"
        >
          <Icon name="lucide:replace" />
        </button>
        <button
          v-if="!readOnly"
          type="button"
          class="flex cursor-pointer items-center hover:text-destructive"
          :aria-label="t('actions.delete')"
          :title="t('actions.delete')"
          @click="clear"
        >
          <Icon name="lucide:trash-2" />
        </button>
      </div>
    </div>

    <!-- Picker -->
    <Dialog
      v-if="!readOnly"
      v-model:open="pickerOpen"
      :modal="true"
    >
      <DialogContent
        class="flex h-[80dvh] flex-col gap-4 !max-w-3xl"
        :scroll-body="false"
      >
        <DialogHeader class="shrink-0">
          <DialogTitle>{{ t('labels.icons.field.selectTitle') }}</DialogTitle>
        </DialogHeader>

        <div
          v-if="sourcesPending"
          class="flex min-h-0 flex-1 items-center justify-center text-muted"
        >
          <Spinner />
        </div>

        <Tabs
          v-else-if="hasAnySource"
          v-model="activeTab"
          class="flex min-h-0 flex-1 flex-col"
        >
          <TabsList
            v-if="hasBothSources"
            class="mb-3 w-fit shrink-0"
          >
            <TabsTrigger value="registry">{{ t('labels.icons.field.tabRegistry') }}</TabsTrigger>
            <TabsTrigger value="iconify">{{ t('labels.icons.field.tabIconify') }}</TabsTrigger>
          </TabsList>

          <TabsContent
            v-if="hasRegistry"
            value="registry"
            class="min-h-0 flex-1 data-[state=inactive]:hidden"
          >
            <IconGrid
              :space-id="spaceId"
              mode="select"
              @icon-select="handleRegistrySelect"
            />
          </TabsContent>

          <TabsContent
            v-if="hasIconify"
            value="iconify"
            class="min-h-0 flex-1 data-[state=inactive]:hidden"
          >
            <IconifyPicker
              :source="iconifySource"
              :allowed-collections="allowedCollections"
              @select="handleIconifySelect"
            />
          </TabsContent>
        </Tabs>

        <div
          v-else
          class="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 text-center"
        >
          <Icon
            name="lucide:shapes"
            size="32"
            class="text-muted"
          />
          <div class="space-y-1">
            <p class="font-semibold text-primary">{{ t('labels.icons.field.emptyTitle') }}</p>
            <p class="max-w-md text-sm text-muted">
              {{
                item.source === 'registry'
                  ? t('labels.icons.field.emptyRegistry')
                  : t('labels.icons.field.emptySources')
              }}
            </p>
          </div>
          <Button
            :as="RouterLink"
            variant="outline"
            size="sm"
            :to="{ name: 'space-icons-index', params: { space: spaceId } }"
          >
            {{ t('labels.icons.field.emptyAction') }}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>
