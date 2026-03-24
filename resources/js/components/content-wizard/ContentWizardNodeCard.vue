<script setup lang="ts">
import type { AcceptableValue } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger } from '~/components/ui/select'
import { cn } from '~/lib/utils'
import type {
  ContentWizardAddPosition,
  ContentWizardCollaborator,
  ContentWizardDraftNode,
  ContentWizardEditableField,
} from '~/types/content-wizard'

import ContentWizardAddMenu from './ContentWizardAddMenu.vue'

const props = withDefaults(
  defineProps<{
    node: ContentWizardDraftNode
    rootTitle?: string
    canMutate?: boolean
    focused?: boolean
    editingField?: ContentWizardEditableField | null
    dropActive?: boolean
    remoteFocusedUsers?: ContentWizardCollaborator[]
    blockOptions?: BlockResource[]
    blocksForBottom?: BlockResource[]
    blocksForRight?: BlockResource[]
  }>(),
  {
    canMutate: true,
    focused: false,
    editingField: null,
    dropActive: false,
    remoteFocusedUsers: () => [],
    blockOptions: () => [],
    blocksForBottom: () => [],
    blocksForRight: () => [],
  }
)


const emit = defineEmits<{
  (event: 'focus'): void
  (event: 'keydown', value: KeyboardEvent): void
  (event: 'start-edit', payload: { field: ContentWizardEditableField; initialChar?: string }): void
  (event: 'input-title', value: string): void
  (event: 'input-slug', value: string): void
  (event: 'commit-title', value: string): void
  (event: 'commit-slug', value: string): void
  (event: 'update-block', value: string): void
  (event: 'toggle-delete'): void
  (event: 'toggle-collapse'): void
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
const hasRemoteFocus = computed(() => props.remoteFocusedUsers.length > 0)
const remoteFocusColor = computed(() => props.remoteFocusedUsers[0]?.color || null)
const canCollapse = computed(() => !props.node.isRootVirtual && props.node.childrenIds.length > 0)


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


const onFocus = (field: string) => {
  emit('focus')
  emit('start-edit', { field })
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
    :draggable="canMutate && !node.isRootVirtual"
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
      v-if="hasRemoteFocus"
      class="pointer-events-none absolute inset-0 rounded-lg border-2"
      :style="{ borderColor: remoteFocusColor || undefined }"
    />

    <div
      v-if="hasRemoteFocus"
      class="pointer-events-none absolute -top-3 left-2"
    >
      <AvatarList
        :users="props.remoteFocusedUsers"
        size="sm"
        :max="3"
        tooltip-side="top"
        class="rounded-full bg-background/90 px-1 py-0.5 shadow-sm"
      />
    </div>

    <div
      v-if="node.isRootVirtual"
      class="flex h-full items-center justify-center"
    >
      <p class="text-sm font-semibold text-primary">
        {{ rootTitle || node.title || $t('labels.contents.canvas.rootNodeTitle') }}
      </p>
    </div>

    <template v-else>
      <div class="flex items-center gap-1">
        <Select
          v-model="selectedBlockId"
          :disabled="!canMutate || !!node.deletedReason"
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
              :disabled="!canMutate || !!node.deletedReason"
              :placeholder="$t('labels.contents.canvas.untitledNode')"
              class="h-7! text-xs px-1.5!"
              @update:model-value="emit('input-title', String($event))"
              @focus="onFocus('start-edit', 'title')"
              @blur="commitTitle"
              @keydown.enter.prevent="commitTitle"
              @keydown.esc.prevent="titleValue = node.title"
            />

            <Input
              ref="slugInputRef"
              v-model="slugValue"
              :disabled="!canMutate || !!node.deletedReason"
              class="h-6! text-xs px-1.5!"
              @update:model-value="emit('input-slug', String($event))"
              @focus="onFocus('start-edit', 'slug')"
              @blur="commitSlug"
              @keydown.enter.prevent="commitSlug"
              @keydown.esc.prevent="slugValue = node.slug"
            />
          </div>
        </div>
      </div>

      <div
        v-if="isActionActive || (canCollapse && node.isCollapsed)"
        class="absolute -top-3.5 -right-3.5 flex"
      >
        <Button
          v-if="canCollapse"
          variant="ghost"
          size="toolbar"
          class="size-7 rounded-full border border-border bg-background shadow-sm"
          @click.stop="emit('toggle-collapse')"
        >
          <Icon :name="node.isCollapsed ? 'lucide:chevron-right' : 'lucide:chevron-down'" />
        </Button>
        <Button
          v-if="canMutate && node.deletedReason === 'self'"
          variant="ghost"
          size="toolbar"
          class="rounded-full border border-border bg-background shadow-sm"
          @click.stop="emit('toggle-delete')"
        >
          <Icon name="lucide:rotate-ccw" />
        </Button>
        <Button
          v-else-if="canMutate"
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
        v-if="canMutate && blocksForRight.length > 0 && !node.deletedReason"
        ref="rightAddMenuRef"
        :blocks="blocksForRight"
        :is-action-active="isActionActive"
        side="right"
        @select="emit('add', { block: $event, position: 'child' })"
      />

      <ContentWizardAddMenu
        v-if="canMutate && blocksForBottom.length > 0 && !node.deletedReason"
        ref="bottomAddMenuRef"
        :blocks="blocksForBottom"
        :is-action-active="isActionActive"
        side="bottom"
        @select="emit('add', { block: $event, position: 'sibling' })"
      />
    </template>

    <ContentWizardAddMenu
      v-if="canMutate && node.isRootVirtual && blocksForBottom.length > 0"
      ref="bottomAddMenuRef"
      :blocks="blocksForBottom"
      :is-action-active="isActionActive"
      side="bottom"
      @select="emit('add', { block: $event, position: 'child' })"
    />
  </div>
</template>
