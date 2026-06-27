<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import IconGrid from '~/components/icons/IconGrid.vue'
import IconifyPicker from '~/components/icons/IconifyPicker.vue'
import IconPreview from '~/components/icons/IconPreview.vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Label } from '~/components/ui/form'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import type { IconResource, IconValue } from '~/types/icons'

const props = defineProps<{
  item: IconSchema & { key: string }
  modelValue?: IconValue | null
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: IconValue | null]
}>()

const { t } = useI18n()

const localValue = ref<IconValue | null>(null)
watch(
  () => props.modelValue,
  (value) => {
    localValue.value = value ? { ...value } : null
  },
  { immediate: true, deep: true }
)

const hasValue = computed(() => !!localValue.value)
const allowIconify = computed(
  () => props.item.source === 'all' || props.item.source === 'collections'
)
const iconifySource = computed<'all' | 'collections'>(() =>
  props.item.source === 'collections' ? 'collections' : 'all'
)

const pickerOpen = ref(false)
const activeTab = ref<'registry' | 'iconify'>('registry')

const setValue = (value: IconValue | null) => {
  localValue.value = value
  emit('update:modelValue', value)
}

const handleRegistrySelect = (icon: IconResource) => {
  setValue({
    source: 'registry',
    id: icon.id,
    external_id: icon.external_id,
    key: `b10cks:${icon.key}`,
    name: icon.name,
    body: icon.body,
    width: icon.width,
    height: icon.height,
  })
  pickerOpen.value = false
}

const handleIconifySelect = (iconName: string) => {
  const namePart = iconName.includes(':') ? iconName.split(':')[1] : iconName
  setValue({
    source: 'iconify',
    key: iconName,
    name: namePart.replace(/-/g, ' '),
  })
  pickerOpen.value = false
}

const clear = () => setValue(null)

const openPicker = () => {
  if (props.readOnly) return
  activeTab.value = 'registry'
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
      :class="[
        'rounded-lg border-1 border-input bg-surface p-6 text-center transition-colors',
        readOnly ? 'cursor-default' : 'cursor-pointer hover:border-muted',
      ]"
      @click="openPicker"
    >
      <Icon
        name="lucide:shapes"
        size="24"
        class="mx-auto mb-3 text-muted"
      />
      <p class="mb-1 text-sm font-semibold text-primary">{{ t('labels.icons.field.add') }}</p>
      <p class="text-xs text-muted">{{ t('labels.icons.field.addHint') }}</p>
    </div>
    <div
      v-else-if="localValue"
      class="group relative flex items-center gap-3 overflow-hidden rounded-lg border border-input bg-surface p-2"
    >
      <div class="flex size-12 shrink-0 items-center justify-center rounded border border-input bg-background text-primary">
        <IconPreview
          v-if="localValue.source === 'registry'"
          :body="localValue.body"
          :width="localValue.width"
          :height="localValue.height"
          size="28"
        />
        <Icon
          v-else
          :name="localValue.key"
          size="28"
        />
      </div>
      <div class="min-w-0 flex-1">
        <p class="truncate font-semibold text-primary">{{ localValue.name }}</p>
        <p class="truncate text-sm text-muted">{{ localValue.key }}</p>
      </div>
      <div class="ml-auto flex items-center gap-2 opacity-0 group-hover:opacity-100">
        <button
          v-if="!readOnly"
          type="button"
          class="flex cursor-pointer items-center hover:text-primary"
          :title="t('actions.icons.replace')"
          @click="openPicker"
        >
          <Icon name="lucide:replace" />
        </button>
        <button
          v-if="!readOnly"
          type="button"
          class="flex cursor-pointer items-center hover:text-destructive"
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
      <DialogContent class="flex h-[80dvh] flex-col gap-4 !max-w-3xl">
        <DialogHeader class="shrink-0">
          <DialogTitle>{{ t('labels.icons.field.selectTitle') }}</DialogTitle>
        </DialogHeader>

        <Tabs
          v-if="allowIconify"
          v-model="activeTab"
          default-value="registry"
          class="flex min-h-0 flex-1 flex-col"
        >
          <TabsList class="mb-3 w-fit shrink-0">
            <TabsTrigger value="registry">{{ t('labels.icons.field.tabRegistry') }}</TabsTrigger>
            <TabsTrigger value="iconify">{{ t('labels.icons.field.tabIconify') }}</TabsTrigger>
          </TabsList>

          <TabsContent
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
            value="iconify"
            class="min-h-0 flex-1 data-[state=inactive]:hidden"
          >
            <IconifyPicker
              :source="iconifySource"
              :allowed-collections="item.allowed_collections || []"
              @select="handleIconifySelect"
            />
          </TabsContent>
        </Tabs>

        <div
          v-else
          class="min-h-0 flex-1"
        >
          <IconGrid
            :space-id="spaceId"
            mode="select"
            @icon-select="handleRegistrySelect"
          />
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>
