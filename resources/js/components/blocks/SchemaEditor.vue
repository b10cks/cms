<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { deepClone } from '@vue/devtools-shared'
import { useSortable } from '@vueuse/integrations/useSortable'
import { AccordionRoot } from 'reka-ui'
import Add from '~/components/blocks/Add.vue'
import Block from '~/components/blocks/Block.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

interface EditorPage {
  header: string
  items: string[]
}

interface AddFieldPayload {
  type: string
  key: string
}

const props = defineProps<{
  schema: Record<string, unknown>
  editor: EditorPage[]
  readonly?: boolean
}>()

const emit = defineEmits(['update:schema', 'update:editor'])
const { alert } = useAlertDialog()

const localSchema = ref({ ...props.schema })
const localEditor = ref(deepClone(props.editor ?? []))

if (localEditor.value.length === 0) {
  localEditor.value.push({
    header: 'General',
    items: Object.keys(localSchema.value),
  })
}

const activeTab = ref(0)

const addPage = () => {
  localEditor.value.push({
    header: `Page ${localEditor.value.length + 1}`,
    items: [],
  })

  activeTab.value = localEditor.value.length - 1
  emitEditorUpdate()
}

const deletePage = async (pageIndex: number) => {
  const confirmed = await alert.confirm(
    `Are you sure you want to delete the "${localEditor.value[pageIndex].header}" page?`,
    {
      title: 'Delete Page',
    }
  )

  if (confirmed) {
    const itemsToMove = [...localEditor.value[pageIndex].items]
    if (itemsToMove.length > 0) {
      const moveConfirmed = await alert.confirm(
        `Would you like to move the ${itemsToMove.length} field(s) to another page?`,
        {
          title: 'Move Fields',
        }
      )

      if (moveConfirmed) {
        const targetPageIndex = pageIndex === 0 ? 1 : 0
        localEditor.value[targetPageIndex].items.push(...itemsToMove)
      }
    }

    localEditor.value.splice(pageIndex, 1)

    if (activeTab.value >= localEditor.value.length) {
      activeTab.value = localEditor.value.length - 1
    }

    emitEditorUpdate()
  }
}

const deleteField = async (key: string) => {
  const fieldName = localSchema.value[key]?.name || key
  const confirmed = await alert.confirm(
    `Are you sure you want to delete the "${fieldName}" field?`,
    {
      title: 'Delete Field',
    }
  )

  if (confirmed) {
    const updatedSchema = { ...localSchema.value }
    delete updatedSchema[key]

    localEditor.value.forEach((page) => {
      const keyIndex = page.items.indexOf(key)
      if (keyIndex !== -1) {
        page.items.splice(keyIndex, 1)
      }
    })

    emitSchemaUpdate(updatedSchema)
    emitEditorUpdate()
  }
}

const addField = async (payload: AddFieldPayload): Promise<boolean> => {
  const { type, key } = payload

  if (localSchema.value[key]) {
    await alert.message(`A field with key "${key}" already exists.`, {
      title: 'Duplicate Key',
    })

    return true
  }

  updateSchemaItem(key, createDefaultSchemaForType(type, key))
  localEditor.value[activeTab.value].items.push(key)

  emitEditorUpdate()

  return false
}

const moveFieldToPage = (key: string, pageIndex: number) => {
  localEditor.value.forEach((page) => {
    const keyIndex = page.items.indexOf(key)
    if (keyIndex !== -1) {
      page.items.splice(keyIndex, 1)
    }
  })

  localEditor.value[pageIndex].items.push(key)
  emitEditorUpdate()
}

const createDefaultSchemaForType = (type: string, key: string) => {
  const name = key
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, (l) => l.toUpperCase())

  const baseSchema = {
    name,
    required: false,
    translatable: false,
    type,
  }

  switch (type) {
    case 'text':
      return {
        ...baseSchema,
        default: '',
      }
    case 'textarea':
      return {
        ...baseSchema,
        default: '',
      }
    case 'markdown':
      return {
        ...baseSchema,
        default: '',
      }
    case 'richtext':
      return {
        ...baseSchema,
        html_classes: [],
        default: {},
      }
    case 'boolean':
      return {
        ...baseSchema,
        default: false,
        show_inline: true,
      }
    case 'number':
      return {
        ...baseSchema,
        default: 0,
      }
    case 'link':
      return {
        ...baseSchema,
        asset_link_type: true,
        email_link_type: false,
        allow_target_blank: true,
        default: '',
      }
    case 'option':
      return {
        ...baseSchema,
        options: [],
        exclude_empty: false,
        default: '',
      }
    case 'blocks':
      return {
        ...baseSchema,
        restrict_blocks: false,
        block_whitelist: [],
        restrict_tags: false,
        tag_whitelist: [],
        default: [],
      }
    case 'meta':
      return {
        ...baseSchema,
        type: 'meta',
        translatable: true,
        has_og_tags: false,
      }
    case 'date':
      return {
        ...baseSchema,
        type: 'date',
        translatable: false,
        format: 'date',
        min: undefined,
        max: undefined,
        use_current_as_default: false,
      }
    default:
      return baseSchema
  }
}

const emitSchemaUpdate = (value) => {
  emit('update:schema', value)
}

const emitEditorUpdate = () => {
  emit('update:editor', localEditor.value)
}

const tabsContainer = useTemplateRef('tabsContainer')

watch(
  () => localEditor.value.length,
  (length) => {
    if (length > 1 && tabsContainer.value) {
      setupTabsSortable()
    }
  },
  { immediate: true }
)

const setupTabsSortable = () => {
  nextTick(() => {
    if (!tabsContainer.value || props.readonly) return

    useSortable(tabsContainer.value, localEditor.value, {
      handle: '[tab-handle]',
      animation: 150,
      onEnd: (event) => {
        const { oldIndex, newIndex } = event
        if (oldIndex === newIndex) return

        if (activeTab.value === oldIndex) {
          activeTab.value = newIndex
        } else if (activeTab.value === newIndex) {
          activeTab.value = oldIndex
        }

        emitEditorUpdate()
      },
    })
  })
}

const setupFieldSortable = (pageIndex: number, element: HTMLElement) => {
  if (props.readonly) return

  useSortable(element, localEditor.value[pageIndex].items, {
    handle: '[draggable]',
    group: 'schema-fields',
    animation: 150,
    onEnd: () => {
      emitEditorUpdate()
    },
  })
}

onMounted(() => {
  if (localEditor.value.length > 1) {
    setupTabsSortable()
  }
})

const updateSchemaItem = (key: string, value: unknown) => {
  emitSchemaUpdate({ ...localSchema.value, [key]: value })
}

watch(
  () => props.schema,
  (newSchema) => {
    localSchema.value = deepClone(newSchema)
  },
  { deep: true }
)

watch(
  () => props.editor,
  (newEditor) => {
    if (JSON.stringify(newEditor) !== JSON.stringify(localEditor.value)) {
      localEditor.value = [...newEditor]
    }
  },
  { deep: true }
)
</script>

<template>
  <div class="schema-editor">
    <div class="mb-2">
      <div
        ref="tabsContainer"
        class="flex items-center gap-1 rounded-xl bg-surface py-1 pr-2 pl-1"
      >
        <button
          v-for="(page, index) in localEditor"
          :key="index"
          type="button"
          :class="[
            'group grab-handle relative inline-flex cursor-pointer items-center gap-2 rounded-lg py-1 pr-1 pl-2 font-semibold',
            activeTab === index ? 'bg-input' : '',
          ]"
          @click="activeTab = index"
        >
          <Icon
            name="lucide:grip-horizontal"
            class="shrink-0 cursor-ew-resize"
            tab-handle
          />
          <Input
            v-model="page.header"
            :disabled="readonly"
            name="header"
            type="text"
            class="!bg-background"
          />
          <button
            v-if="localEditor.length > 1 && !readonly"
            type="button"
            title="Delete page"
            class="absolute top-1/2 right-4 z-10 -translate-y-1/2 transform cursor-pointer opacity-0 transition-opacity group-hover:opacity-100 hover:text-destructive"
            @click.stop="deletePage(index)"
          >
            <Icon
              name="lucide:trash-2"
              size="0.8rem"
            />
          </button>
        </button>

        <Button
          v-if="!readonly"
          title="Add new page"
          type="button"
          size="icon"
          class="ml-auto"
          @click="addPage"
        >
          <Icon name="lucide:plus" />
        </Button>
      </div>
    </div>

    <div class="tab-content rounded-lg border border-input p-2">
      <div
        v-for="(page, pageIndex) in localEditor"
        v-show="activeTab === pageIndex"
        :key="pageIndex"
        class="grid gap-4"
      >
        <AccordionRoot
          v-slot="{ modelValue }"
          :ref="(el) => el && setupFieldSortable(pageIndex, el)"
          type="multiple"
          class="grid gap-2"
        >
          <Block
            v-for="key in page.items"
            :key="key"
            :name="key"
            :item="localSchema[key]"
            :pages="localEditor"
            :current-page="pageIndex"
            :is-open="modelValue.includes(key)"
            :readonly="readonly"
            class="rounded-md border border-border bg-surface p-2"
            @update:item="(v) => updateSchemaItem(key, v)"
            @to-page="moveFieldToPage(key, $event)"
            @delete="deleteField(key)"
          />
        </AccordionRoot>

        <Add
          v-if="!readonly"
          @add="addField"
        />
      </div>
    </div>
  </div>
</template>
