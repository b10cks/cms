<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'
import { useSortable } from '@vueuse/integrations/useSortable'
import { AccordionRoot } from 'reka-ui'
import type { ComponentPublicInstance, Ref } from 'vue'
import { toast } from 'vue-sonner'

import Add from '~/components/blocks/Add.vue'
import { useFieldClipboard } from '~/composables/useFieldClipboard'
import Block from '~/components/blocks/Block.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

interface AddFieldPayload {
  type: string
  key: string
}

const props = defineProps<{
  schema: Record<string, SchemaType>
  editor: EditorPage[]
  readonly?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:schema', payload: Record<string, SchemaType>): void
  (e: 'update:editor', payload: EditorPage[]): void
}>()
const { alert } = useAlertDialog()
const { $t } = useI18n()
const { copyField: copyFieldToClipboard, clipboardField, hasField } = useFieldClipboard()

const localSchema = ref<Record<string, SchemaType>>(
  deepClone(props.schema ?? {}) as Record<string, SchemaType>
)
const localEditor = ref<EditorPage[]>(deepClone(props.editor ?? []) as EditorPage[])

if (localEditor.value.length === 0) {
  localEditor.value.push({
    header: 'General',
    items: Object.keys(localSchema.value),
  })
}

const activeTab = ref(0)

const resolveSortableElement = (
  element: Element | ComponentPublicInstance | null
): HTMLElement | null => {
  if (element instanceof HTMLElement) {
    return element
  }

  if (element && '$el' in element && element.$el instanceof HTMLElement) {
    return element.$el
  }

  return null
}

const isAccordionItemOpen = (modelValue: unknown, key: string) => {
  if (Array.isArray(modelValue)) {
    return modelValue.includes(key)
  }

  return typeof modelValue === 'string' ? modelValue === key : false
}

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
  const fieldName = localSchema.value[key]?.name ?? key
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

  updateSchemaItem(key, createDefaultSchemaForType(type, key) as SchemaType)
  localEditor.value[activeTab.value].items.push(key)

  emitEditorUpdate()

  return false
}

const handleAddField = async (payload: AddFieldPayload & { resolve: (value: boolean) => void }) => {
  const result = await addField(payload)
  payload.resolve(result)
}

const FIELD_KEY_PATTERN = /^[a-z][a-z0-9A-Z]+$/
const FIELD_KEY_BLACKLIST = ['key', 'block']

const renameField = async (oldKey: string, newKey: string): Promise<boolean> => {
  if (!FIELD_KEY_PATTERN.test(newKey) || FIELD_KEY_BLACKLIST.includes(newKey)) {
    await alert.message($t('labels.blocks.renameField.invalidKey') as string, {
      title: $t('labels.blocks.renameField.title') as string,
    })
    return false
  }

  if (localSchema.value[newKey]) {
    await alert.message($t('labels.blocks.renameField.duplicateKey', { key: newKey }) as string, {
      title: $t('labels.blocks.renameField.title') as string,
    })
    return false
  }

  const confirmed = await alert.confirm(
    $t('labels.blocks.renameField.message', { from: oldKey, to: newKey }) as string,
    {
      title: $t('labels.blocks.renameField.title') as string,
      confirmLabel: $t('labels.blocks.renameField.confirmLabel') as string,
    }
  )

  if (!confirmed) return false

  const updatedSchema = Object.fromEntries(
    Object.entries(deepClone(localSchema.value) as Record<string, SchemaType>).map(
      ([key, field]) => {
        if (field.conditions?.rules?.some((rule) => rule.field === oldKey)) {
          field.conditions = {
            ...field.conditions,
            rules: field.conditions.rules.map((rule) =>
              rule.field === oldKey ? { ...rule, field: newKey } : rule
            ),
          }
        }

        return [key === oldKey ? newKey : key, field]
      }
    )
  ) as Record<string, SchemaType>

  localEditor.value.forEach((page) => {
    page.items = page.items.map((item) => (item === oldKey ? newKey : item))
  })

  emitSchemaUpdate(updatedSchema)
  emitEditorUpdate()

  return true
}

const handleRenameField = async (
  oldKey: string,
  payload: { key: string; resolve: (value: boolean) => void }
) => {
  payload.resolve(await renameField(oldKey, payload.key))
}

const ensureUniqueKey = (desired: string): string => {
  if (!localSchema.value[desired]) return desired

  let candidate = `${desired}Copy`
  let index = 2
  while (localSchema.value[candidate]) {
    candidate = `${desired}Copy${index++}`
  }

  return candidate
}

const insertField = (key: string, field: SchemaType, pageIndex: number, atIndex?: number) => {
  updateSchemaItem(key, field)

  const items = localEditor.value[pageIndex].items
  items.splice(atIndex ?? items.length, 0, key)

  emitEditorUpdate()
}

const duplicateField = (key: string) => {
  const source = localSchema.value[key]
  if (!source) return

  const newKey = ensureUniqueKey(`${key}Copy`)
  const pageIndex = localEditor.value.findIndex((page) => page.items.includes(key))
  const targetPage = pageIndex === -1 ? activeTab.value : pageIndex
  const position =
    pageIndex === -1 ? undefined : localEditor.value[pageIndex].items.indexOf(key) + 1

  insertField(newKey, deepClone(source) as SchemaType, targetPage, position)
}

const copyField = async (key: string) => {
  const field = localSchema.value[key]
  if (!field) return

  await copyFieldToClipboard(key, deepClone(field) as SchemaType)
  toast.success(
    $t('labels.blocks.fieldClipboard.copied', { name: field.name || key }) as string
  )
}

const pasteField = () => {
  const item = clipboardField.value
  if (!item) return

  const key = ensureUniqueKey(item.key)
  insertField(key, deepClone(item.field) as SchemaType, activeTab.value)
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

const createDefaultSchemaForType = (type: string, key: string): SchemaType => {
  const name = key
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, (l) => l.toUpperCase())

  const baseSchema = {
    name,
    required: false,
    translatable: false,
    indexable: ['text', 'textarea', 'markdown', 'richtext'].includes(type),
    conditions: null,
    validation: null,
  }

  switch (type) {
    case 'text':
      return {
        ...baseSchema,
        type: 'text',
        translatable: true,
        default: '',
      } as TextSchema
    case 'textarea':
      return {
        ...baseSchema,
        type: 'textarea',
        translatable: true,
        default: '',
      } as TextareaSchema
    case 'markdown':
      return {
        ...baseSchema,
        type: 'markdown',
        translatable: true,
        default: '',
      } as MarkdownSchema
    case 'richtext':
      return {
        ...baseSchema,
        type: 'richtext',
        translatable: true,
        html_classes: [],
        default: {},
      } as RichTextSchema
    case 'boolean':
      return {
        ...baseSchema,
        type: 'boolean',
        default: false,
        show_inline: true,
      } as BooleanSchema
    case 'number':
      return {
        ...baseSchema,
        type: 'number',
        default: 0,
      } as NumberSchema
    case 'link':
      return {
        ...baseSchema,
        type: 'link',
        translatable: true,
        asset_link_type: true,
        email_link_type: true,
        allow_target_blank: true,
        default: '',
      } as LinkSchema
    case 'option':
      return {
        ...baseSchema,
        type: 'option',
        options: [],
        source: 'self',
        data_source_id: null,
        exclude_empty: false,
        default: null,
      } as OptionSchema
    case 'options':
      return {
        ...baseSchema,
        type: 'options',
        options: [],
        source: 'self',
        data_source_id: null,
        min: null,
        max: null,
        default: [],
      } as OptionsSchema
    case 'references':
      return {
        ...baseSchema,
        type: 'references',
        block_whitelist: [],
        min: null,
        max: null,
        default: [],
      } as ReferencesSchema
    case 'asset':
      return {
        ...baseSchema,
        type: 'asset',
        file_types: ['all'],
        folder_id: null,
        default: null,
      } as AssetSchema
    case 'multi_assets':
      return {
        ...baseSchema,
        type: 'multi_assets',
        file_types: ['all'],
        min: null,
        max: null,
        default: [],
      } as MultiAssetsSchema
    case 'icon':
      return {
        ...baseSchema,
        type: 'icon',
        source: 'all',
        allowed_collections: [],
        default: null,
      } as IconSchema
    case 'geo':
      return {
        ...baseSchema,
        type: 'geo',
        key_style: 'lat_lng',
        altitude: false,
        map: true,
        default: null,
      } as GeoSchema
    case 'price':
      return {
        ...baseSchema,
        type: 'price',
        base_currency: 'EUR',
        currencies: [],
        default: null,
      } as PriceSchema
    case 'blocks':
      return {
        ...baseSchema,
        type: 'blocks',
        restrict_blocks: false,
        block_whitelist: [],
        restrict_tags: false,
        tag_whitelist: [],
        default: [],
      } as unknown as BlocksSchema
    case 'meta':
      return {
        ...baseSchema,
        type: 'meta',
        translatable: true,
        has_og_tags: false,
      } as MetaSchema
    case 'date':
      return {
        ...baseSchema,
        type: 'date',
        translatable: false,
        format: 'date',
        min: undefined,
        max: undefined,
        use_current_as_default: false,
      } as DateSchema
    case 'table':
      return {
        ...baseSchema,
        type: 'table',
        translatable: true,
        has_thead: true,
        min: null,
        max: null,
        columns: [],
        default: {
          header: {},
          rows: [],
        },
      } as TableSchema
    default:
      return {
        ...baseSchema,
        type: 'text',
        translatable: true,
        default: '',
      } as TextSchema
  }
}

const emitSchemaUpdate = (value: Record<string, SchemaType>) => {
  emit('update:schema', value)
}

const emitEditorUpdate = () => {
  emit('update:editor', localEditor.value)
}

const tabsContainer = useTemplateRef<HTMLElement>('tabsContainer')

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

    ;(useSortable as any)(tabsContainer.value, localEditor.value, {
      handle: '[tab-handle]',
      animation: 150,
      onEnd: (event: { oldIndex?: number | null; newIndex?: number | null }) => {
        const oldIndex = event.oldIndex
        const newIndex = event.newIndex
        if (oldIndex == null || newIndex == null || oldIndex === newIndex) return

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
  const page = localEditor.value[pageIndex]
  if (!page) return

  const pageItems = toRef(page, 'items')

  ;(useSortable as any)(element, pageItems, {
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

const updateSchemaItem = (key: string, value: SchemaType) => {
  emitSchemaUpdate({ ...localSchema.value, [key]: value })
}

watch(
  () => props.schema,
  (newSchema) => {
    localSchema.value = deepClone(newSchema) as Record<string, SchemaType>
  },
  { deep: true }
)

watch(
  () => props.editor,
  (newEditor) => {
    if (JSON.stringify(newEditor) !== JSON.stringify(localEditor.value)) {
      localEditor.value = deepClone(newEditor) as EditorPage[]
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
          :ref="
            (el: Element | ComponentPublicInstance | null) => {
              const sortableElement = resolveSortableElement(el)
              if (sortableElement) {
                setupFieldSortable(pageIndex, sortableElement)
              }
            }
          "
          type="multiple"
          class="grid gap-2"
        >
          <Block
            v-for="key in page.items"
            :key="key"
            :name="key"
            :item="localSchema[key] as SchemaType"
            :schema="localSchema"
            :pages="localEditor"
            :current-page="pageIndex"
            :is-open="isAccordionItemOpen(modelValue, key)"
            :readonly="Boolean(readonly)"
            class="rounded-md border border-border bg-surface p-2"
            @update:item="(v: SchemaType) => updateSchemaItem(key, v)"
            @to-page="moveFieldToPage(key, $event)"
            @rename="handleRenameField(key, $event)"
            @duplicate="duplicateField(key)"
            @copy="copyField(key)"
            @delete="deleteField(key)"
          />
        </AccordionRoot>

        <div
          v-if="!readonly"
          class="flex items-start gap-2"
        >
          <Add
            class="grow"
            @add="handleAddField"
          />
          <Button
            v-if="hasField && clipboardField"
            type="button"
            variant="outline"
            :title="String($t('labels.blocks.fieldClipboard.paste', { name: clipboardField.field.name || clipboardField.key }))"
            @click="pasteField"
          >
            <Icon name="lucide:clipboard-paste" />
            {{ $t('actions.blocks.pasteField') }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
