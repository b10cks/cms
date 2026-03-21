<script setup lang="ts">
import type { AcceptableValue } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger } from '~/components/ui/select'
import { cn } from '~/lib/utils'
import type {
  ContentWizardAddPosition,
  ContentWizardDraftNode,
  ContentWizardEditableField,
} from '~/types/content-wizard'

import ContentWizardAddMenu from './ContentWizardAddMenu.vue'

const props = withDefaults(
  defineProps<{
    node: ContentWizardDraftNode
    rootTitle?: string
    focused?: boolean
    editingField?: ContentWizardEditableField | null
    dropActive?: boolean
    blockOptions?: BlockResource[]
    blocksForBottom?: BlockResource[]
    blocksForRight?: BlockResource[]
  }>(),
  {
    focused: false,
    editingField: null,
    dropActive: false,
    blockOptions: () => [],
    blocksForBottom: () => [],
    blocksForRight: () => [],
  }
)


const emit = defineEmits<{
  (event: 'focus'): void
  (event: 'keydown', value: KeyboardEvent): void
  (event: 'start-edit', payload: { field: ContentWizardEditableField; initialChar?: string }): void
  (event: 'commit-title', value: string): void
  (event: 'commit-slug', value: string): void
  (event: 'update-block', value: string): void
  (event: 'toggle-delete'): void
  (event: 'add', payload: { block: BlockResource; position: ContentWizardAddPosition }): void
  (event: 'dragstart', value: DragEvent): void
  (event: 'dragend', value: DragEvent): void
  (event: 'dragenter', value: DragEvent): void
  (event: 'dragover', value: DragEvent): void
  (event: 'dragleave', value: DragEvent): void
  (event: 'drop', value: DragEvent): void
}>()


const wrapperRef = ref<HTMLElement | null>(null)
const titleInputRef = useTemplateRef<HTMLInputElement | null>('titleInputRef')
const slugInputRef = useTemplateRef<HTMLInputElement | null>('slugInputRef')
const rightAddMenuRef = ref<InstanceType<typeof ContentWizardAddMenu> | null>(null)
const bottomAddMenuRef = ref<InstanceType<typeof ContentWizardAddMenu> | null>(null)


const titleValue = ref(props.node.title)
const slugValue = ref(props.node.slug)
const selectedBlockId = ref(props.node.blockId)
const selectedBlock = computed(
  () => props.blockOptions.find((block) => block.id === selectedBlockId.value) || null
)


watch(
  () => props.node.title,
  (value) => {
    titleValue.value = value
  }
)


watch(
  () => props.node.slug,
  (value) => {
    slugValue.value = value
  }
)


watch(
  () => props.node.blockId,
  (value) => {
    selectedBlockId.value = value
  }
)


watch(
  () => props.editingField,
  async (field) => {
    await nextTick()


    if (field === 'title') {
      titleInputRef.value?.focus()
      titleInputRef.value?.select()
    }


    if (field === 'slug') {
      slugInputRef.value?.focus()
      slugInputRef.value?.select()
    }
  }
)


const isActionActive = computed(() => props.focused || props.dropActive)


const isChanged = computed(
  () => props.node.changes.created || props.node.changes.updated || props.node.changes.moved
)
const isDeleted = computed(() => props.node.deletedReason)


const focusCard = () => {
  wrapperRef.value?.focus()
}


const openAddMenu = (position: ContentWizardAddPosition) => {
  const menu = position === 'child' ? rightAddMenuRef.value : bottomAddMenuRef.value
  menu?.openMenu()
}


defineExpose({
  focusCard,
  openAddMenu,
})


const commitTitle = () => emit('commit-title', titleValue.value)
const commitSlug = () => emit('commit-slug', slugValue.value)


const handleDragOver = (event: DragEvent) => {
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = event.altKey || event.ctrlKey || event.metaKey ? 'copy' : 'move'
  }


  emit('dragover', event)
}


const handleBlockChange = (value: AcceptableValue) => {
  if (typeof value === 'string' || typeof value === 'number') {
    emit('update-block', String(value))
  }
}
</script>

<template>
  <div
    ref="wrapperRef"
    data-node-card
    :draggable="!node.isRootVirtual"
    tabindex="0"
    :class="
      cn(
        'group absolute rounded-lg bg-surface p-1 shadow-soft outline-none transition-all',
        isDeleted ? 'ring-1 ring-destructive opacity-30' : '',
        isChanged && !isDeleted ? 'ring-1 ring-warning' : '',
        focused ? ' ring-2 ring-accent!' : '',
        dropActive ? 'ring-2 ring-accent!' : '',
        node.isAiAltered && !focused && !dropActive ? 'ring-2 ring-ai!' : ''
      )
    "
    @focus="emit('focus')"
    @keydown="emit('keydown', $event)"
    @dragstart="emit('dragstart', $event)"
    @dragend="emit('dragend', $event)"
    @dragenter.prevent="emit('dragenter', $event)"
    @dragover.prevent="handleDragOver"
    @dragleave.prevent="emit('dragleave', $event)"
    @drop.stop.prevent="emit('drop', $event)"
  >
    <div
      v-if="node.isRootVirtual"
      class="flex h-full items-center justify-center"
    >
      <p class="text-sm font-semibold text-primary">
        {{ rootTitle || node.title || $t('labels.contents.wizard.rootNodeTitle') }}
      </p>
    </div>

    <template v-else>
      <div class="flex items-center gap-1">
        <Select
          v-model="selectedBlockId"
          :disabled="!!node.deletedReason"
          @update:model-value="handleBlockChange"
        >
          <SelectTrigger
            data-block-select
            class="flex size-7! items-center justify-center [&>svg:last-child]:hidden"
          >
            <Icon
              :name="`lucide:${selectedBlock?.icon || 'layout-template'}`"
              :style="selectedBlock?.color ? { color: selectedBlock.color } : undefined"
              class="shrink-0"
            />
            <span class="sr-only">
              {{ selectedBlock?.name || $t('labels.contents.fields.block') }}
            </span>
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="block in blockOptions"
              :key="block.id"
              :value="block.id"
            >
              <div class="flex items-center gap-2">
                <Icon
                  :name="block.icon ? `lucide:${block.icon}` : 'lucide:layout-template'"
                  :style="block.color ? { color: block.color } : undefined"
                />
                <span>{{ block.name }}</span>
              </div>
            </SelectItem>
          </SelectContent>
        </Select>

        <div class="min-w-0 flex-1">
          <div class="flex min-h-10 flex-col justify-center gap-1">
            <Input
              ref="titleInputRef"
              v-model="titleValue"
              :disabled="!!node.deletedReason"
              :placeholder="$t('labels.contents.wizard.untitledNode')"
              class="h-7! text-xs px-1.5!"
              @focus="emit('focus')"
              @blur="commitTitle"
              @keydown.enter.prevent="commitTitle"
              @keydown.esc.prevent="titleValue = node.title"
            />

            <Input
              ref="slugInputRef"
              v-model="slugValue"
              :disabled="!!node.deletedReason"
              class="h-6! text-xs px-1.5!"
              @focus="emit('focus')"
              @blur="commitSlug"
              @keydown.enter.prevent="commitSlug"
              @keydown.esc.prevent="slugValue = node.slug"
            />
          </div>
        </div>
      </div>

      <div
        v-if="isActionActive"
        class="absolute -top-3.5 -right-3.5 flex"
      >
        <Button
          v-if="node.deletedReason === 'self'"
          variant="ghost"
          size="toolbar"
          class="rounded-full border border-border bg-background shadow-sm"
          @click.stop="emit('toggle-delete')"
        >
          <Icon name="lucide:rotate-ccw" />
        </Button>
        <Button
          v-else
          variant="ghost"
          size="toolbar"
          class="size-7 rounded-full border border-border bg-background shadow-sm"
          @click.stop="emit('toggle-delete')"
        >
          <Icon name="lucide:trash-2" />
        </Button>
      </div>

      <div
        v-if="node.validationState.errors.length > 0"
        class="mt-1.5 rounded-md border border-destructive/20 bg-destructive-background/15 px-2 py-1 text-[11px] text-destructive"
      >
        <p
          v-for="error in node.validationState.errors"
          :key="`${error.field}:${error.message}`"
          class="leading-relaxed"
        >
          {{ error.message }}
        </p>
      </div>

      <ContentWizardAddMenu
        v-if="blocksForRight.length > 0 && !node.deletedReason"
        ref="rightAddMenuRef"
        :blocks="blocksForRight"
        side="right"
        @select="emit('add', { block: $event, position: 'child' })"
      >
        <button
          type="button"
          :class="
            cn(
              'absolute top-1/2 -right-6 flex size-6 -translate-y-1/2 items-center justify-center rounded-full bg-accent text-primary transition',
              isActionActive
                ? 'pointer-events-auto cursor-pointer opacity-100 scale-50 hover:scale-100'
                : 'pointer-events-none opacity-0 group-hover:pointer-events-auto hover:text-primary'
            )
          "
        >
          <Icon name="lucide:plus" />
        </button>
      </ContentWizardAddMenu>

      <ContentWizardAddMenu
        v-if="blocksForBottom.length > 0 && !node.deletedReason"
        ref="bottomAddMenuRef"
        :blocks="blocksForBottom"
        side="bottom"
        @select="emit('add', { block: $event, position: 'sibling' })"
      >
        <button
          type="button"
          :class="
            cn(
              'absolute -bottom-6 left-1/2 flex size-6 -translate-x-1/2 items-center justify-center rounded-full bg-accent text-primary transition',
              isActionActive
                ? 'pointer-events-auto cursor-pointer opacity-100 scale-50 hover:scale-100'
                : 'pointer-events-none opacity-0 group-hover:pointer-events-auto hover:text-primary'
            )
          "
        >
          <Icon name="lucide:plus" />
        </button>
      </ContentWizardAddMenu>
    </template>

    <ContentWizardAddMenu
      v-if="node.isRootVirtual && blocksForBottom.length > 0"
      ref="bottomAddMenuRef"
      :blocks="blocksForBottom"
      side="bottom"
      @select="emit('add', { block: $event, position: 'child' })"
    >
      <button
        type="button"
        :class="
          cn(
            'absolute -bottom-3.5 left-1/2 flex size-7 -translate-x-1/2 items-center justify-center rounded-full bg-accent text-primary transition',
            isActionActive
              ? 'pointer-events-auto opacity-100'
              : 'pointer-events-none opacity-0 scale-50 group-hover:pointer-events-auto group-hover:scale-100 hover:text-primary'
          )
        "
      >
        <Icon name="lucide:plus" />
      </button>
    </ContentWizardAddMenu>
  </div>
</template>
