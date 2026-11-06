<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'

import Icon from '~/components/Icon.vue'
import { FormField } from '~/components/ui/form'
import SortHandle from '~/components/ui/SortHandle.vue'
import { contentPickerItems, contentPickerPaths } from '~/lib/content-picker-items'
import { focusGrip, moveInList, sortableOptions } from '~/lib/sortable'

import LinkEntryPicker from './LinkEntryPicker.vue'
import ReferenceRow from './ReferenceRow.vue'

/**
 * References to other entries. One reference is a field like the asset field;
 * several are cards in a list frame like the gallery, with the round plus at the end.
 */
const props = defineProps<{
  modelValue?: string[] | null
  item: ReferencesSchema & { key: string }
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[] | null]
}>()

const { $t } = useI18n()
const { useContentMenuQuery } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const localValue = ref<string[]>([])

watch(
  () => props.modelValue,
  (value) => {
    localValue.value = value ? [...value] : []
  },
  { immediate: true, deep: true }
)

const isSingle = computed(() => props.item.type === 'reference' || props.item.max === 1)
const canAddMore = computed(
  () => !props.readOnly && (!props.item.max || localValue.value.length < props.item.max)
)

const paths = computed(() =>
  contentPickerPaths(contentMenu.value ? contentPickerItems(contentMenu.value) : [])
)

const entryOf = (id: string) => contentMenu.value?.[id]

const updateValue = () => {
  const enough = localValue.value.length >= (props.item.min || 0)
  emit('update:modelValue', enough && localValue.value.length > 0 ? [...localValue.value] : null)
}

const add = (id: string) => {
  if (!canAddMore.value || localValue.value.includes(id)) return
  localValue.value.push(id)
  updateValue()
}

const replace = (index: number, id: string) => {
  localValue.value[index] = id
  updateValue()
}

const remove = (index: number) => {
  localValue.value.splice(index, 1)
  updateValue()
}

const listRef = ref<HTMLElement | null>(null)
// Its own grip selector, so a references list nested in a blocks row never picks up the grips around it.
const sortId = useId()
const handleSelector = `[data-reference-handle="${sortId}"]`

useSortable(
  listRef,
  localValue,
  sortableOptions({
    handle: handleSelector,
    draggable: '>[data-reference-row]',
    disabled: props.readOnly,
    // useSortable writes the new order on the next tick.
    onEnd: () => nextTick(updateValue),
  })
)

const move = async (index: number, delta: -1 | 1) => {
  if (props.readOnly || !moveInList(localValue.value, index, delta)) return

  updateValue()
  await nextTick()
  focusGrip(listRef.value, handleSelector, index + delta)
}

const entryRoute = (id: string) => ({
  name: 'space-content-contentId',
  params: { space: props.spaceId, contentId: id },
})
</script>

<template>
  <FormField
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || undefined"
    :required="item.required"
  >
    <template
      v-if="!isSingle && item.max"
      #label-end
    >
      <span class="mr-8 text-sm text-muted tabular-nums">
        {{ $t('labels.references.referencesCount', { current: localValue.length, max: item.max }) }}
      </span>
    </template>

    <template v-if="isSingle">
      <div
        v-if="localValue[0]"
        class="flex min-w-0 items-center gap-3 rounded-lg border border-input-border bg-input p-1.5 pr-2 shadow-sm"
      >
        <ReferenceRow
          :entry="entryOf(localValue[0])"
          :path="paths.get(localValue[0])"
        />
        <div class="flex shrink-0">
          <LinkEntryPicker
            v-if="!readOnly"
            :space-id="spaceId"
            :content="localValue[0]"
            :sections="false"
            @select="(id: string) => replace(0, id)"
          >
            <template #trigger>
              <button
                type="button"
                class="row-action"
                :aria-label="$t('labels.references.replace')"
                :title="$t('labels.references.replace')"
              >
                <Icon name="lucide:replace" />
              </button>
            </template>
          </LinkEntryPicker>
          <RouterLink
            class="row-action"
            :to="entryRoute(localValue[0])"
            :aria-label="$t('labels.references.open')"
            :title="$t('labels.references.open')"
          >
            <Icon name="lucide:arrow-up-right" />
          </RouterLink>
          <button
            v-if="!readOnly"
            type="button"
            class="row-action hover:text-destructive-ink"
            :aria-label="$t('labels.references.removeReference')"
            :title="$t('labels.references.removeReference')"
            @click="remove(0)"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </div>
      <LinkEntryPicker
        v-else
        :space-id="spaceId"
        :sections="false"
        :disabled="readOnly"
        @select="(id: string) => replace(0, id)"
      />
    </template>

    <div
      v-else
      class="min-w-0 rounded-2xl border border-border bg-surface px-2"
    >
      <p
        v-if="readOnly && localValue.length === 0"
        class="px-1 py-2 text-center text-sm text-muted"
      >
        {{ $t('labels.references.noReferences') }}
      </p>
      <div
        ref="listRef"
        class="pt-2"
      >
        <div
          v-for="(reference, index) in localValue"
          :key="reference"
          data-reference-row
          class="group relative mb-2 flex min-w-0 items-center gap-3 rounded-lg border border-border bg-card p-2 transition-colors duration-100 hover:border-border-strong"
        >
          <SortHandle
            v-if="!readOnly"
            :data-reference-handle="sortId"
            :label="$t('actions.dragToReorder')"
            class="-mr-1.5 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100"
            @move="(delta) => move(index, delta)"
          />
          <ReferenceRow
            :entry="entryOf(reference)"
            :path="paths.get(reference)"
          />
          <div
            class="absolute top-1/2 right-1.5 flex -translate-y-1/2 rounded-md bg-card opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 has-[[data-state=open]]:opacity-100"
          >
            <LinkEntryPicker
              v-if="!readOnly"
              :space-id="spaceId"
              :content="reference"
              :sections="false"
              :exclude="localValue.filter((id) => id !== reference)"
              @select="(id: string) => replace(index, id)"
            >
              <template #trigger>
                <button
                  type="button"
                  class="row-action"
                  :aria-label="$t('labels.references.replace')"
                  :title="$t('labels.references.replace')"
                >
                  <Icon name="lucide:replace" />
                </button>
              </template>
            </LinkEntryPicker>
            <RouterLink
              class="row-action"
              :to="entryRoute(reference)"
              :aria-label="$t('labels.references.open')"
              :title="$t('labels.references.open')"
            >
              <Icon name="lucide:arrow-up-right" />
            </RouterLink>
            <button
              v-if="!readOnly"
              type="button"
              class="row-action hover:text-destructive-ink"
              :aria-label="$t('labels.references.removeReference')"
              :title="$t('labels.references.removeReference')"
              @click="remove(index)"
            >
              <Icon name="lucide:trash-2" />
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="canAddMore"
        class="group/add relative mb-2 flex h-7 items-center justify-center"
      >
        <div
          class="absolute inset-x-0 top-1/2 -mt-px border-t-2 border-accent opacity-0 transition-opacity group-hover/add:opacity-100 group-has-[:focus-visible]/add:opacity-100 group-has-[[data-state=open]]/add:opacity-100"
        />
        <LinkEntryPicker
          :space-id="spaceId"
          :sections="false"
          :exclude="localValue"
          class="relative"
          @select="add"
        >
          <template #trigger>
            <button
              type="button"
              class="flex size-6 cursor-pointer items-center justify-center rounded-full border border-border-strong bg-background text-muted transition-colors group-hover/add:border-transparent group-hover/add:bg-accent-fill group-hover/add:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[state=open]:border-transparent data-[state=open]:bg-accent-fill data-[state=open]:text-accent-foreground"
              :aria-label="$t('actions.references.add')"
              :title="$t('actions.references.add')"
            >
              <Icon name="lucide:plus" />
            </button>
          </template>
        </LinkEntryPicker>
      </div>
    </div>
  </FormField>
</template>
