<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useSortable } from '@vueuse/integrations/useSortable'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Switch } from '~/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'

export interface ColumnDefinition {
  key: string
  label: string
  type?: 'text' | 'switch' | 'select' | 'custom'
  width?: string
  placeholder?: string
  required?: boolean
  readonly?: boolean
  options?:
    | Array<{
        value: string
        label: string
        disabled?: boolean
      }>
    | ((item: TableItem) => Array<{
        value: string
        label: string
        disabled?: boolean
      }>)
}

export interface TableItem {
  [key: string]: string | number | boolean | undefined
}

const props = withDefaults(
  defineProps<{
    items: TableItem[]
    columns: ColumnDefinition[]
    allowSort?: boolean
    showAddRow?: boolean
    canAdd?: boolean
    canEdit?: boolean
    canDelete?: boolean
    newItemTemplate?: TableItem
    addButtonLabel?: string
    removeButtonLabel?: string
  }>(),
  {
    allowSort: false,
    showAddRow: true,
    canAdd: true,
    canEdit: true,
    canDelete: true,
    newItemTemplate: () => ({}),
    addButtonLabel: 'actions.add',
    removeButtonLabel: 'actions.remove',
  }
)

const id = `settings-table-${Math.random().toString(36).substring(2, 15)}`

const emit = defineEmits<{
  'update:items': [items: TableItem[]]
  add: [item: TableItem]
  remove: [index: number, item: TableItem]
}>()

const tableBodyRef = useTemplateRef<HTMLElement>('tableBodyRef')
const localItems = ref<TableItem[]>([...(props.items || [])])
const newItem = ref<TableItem>({ ...props.newItemTemplate })

watch(
  () => props.items,
  (newItems) => {
    localItems.value = [...newItems]
  },
  { deep: true }
)

if (props.allowSort) {
  ;(useSortable as any)(tableBodyRef, localItems, {
    handle: '.sort-handle',
    animation: 150,
    disabled: !props.canEdit,
    onEnd: () => {
      nextTick(() => emit('update:items', [...localItems.value]))
    },
  })
}

const canAddItem = computed(() => {
  if (!props.canAdd) {
    return false
  }

  return props.columns
    .filter((col) => col.required)
    .every((col) => {
      const value = newItem.value[col.key]
      return value !== undefined && value !== null && value !== ''
    })
})

const addItem = () => {
  if (canAddItem.value) {
    const itemToAdd = { ...newItem.value }
    emit('add', itemToAdd)
    newItem.value = { ...props.newItemTemplate }
    nextTick(() => {
      ;(document.querySelector(`#${id}-new-row input`) as HTMLInputElement)?.focus()
    })
  }
}

const removeItem = (index: number) => {
  const item = localItems.value[index]
  localItems.value.splice(index, 1)
  emit('remove', index, item)
}

const getSelectValue = (item: TableItem, key: string) => {
  const value = item[key]
  return typeof value === 'string' ? value : undefined
}

const updateSelectValue = (item: TableItem, key: string, value: unknown) => {
  if (typeof value === 'string') {
    item[key] = value
  }
}

const getColumnOptions = (column: ColumnDefinition, item: TableItem) => {
  return typeof column.options === 'function' ? column.options(item) : (column.options ?? [])
}
</script>

<template>
  <div class="overflow-hidden rounded-md">
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead
            v-if="allowSort"
            class="w-4"
          />
          <TableHead
            v-for="column in columns"
            :key="column.key"
            :class="column.width"
          >
            {{ column.label }}
          </TableHead>
          <TableHead class="w-12" />
        </TableRow>
      </TableHeader>
      <TableBody ref="tableBodyRef">
        <TableRow
          v-for="(item, index) in localItems"
          :key="index"
        >
          <TableCell
            v-if="allowSort"
            class="sort-handle w-4 cursor-ns-resize"
          >
            <Icon name="lucide:grip-vertical" />
          </TableCell>
          <TableCell
            v-for="column in columns"
            :key="column.key"
          >
            <slot
              v-if="column.type === 'custom'"
              :name="`cell-${column.key}`"
              :item="item"
              :index="index"
              :column="column"
            />
            <Switch
              v-else-if="column.type === 'switch'"
              v-model="item[column.key] as boolean"
              :disabled="column.readonly || !canEdit"
            />
            <Select
              v-else-if="column.type === 'select'"
              :model-value="getSelectValue(item, column.key)"
              :disabled="column.readonly || !canEdit"
              @update:model-value="(value) => updateSelectValue(item, column.key, value)"
            >
              <SelectTrigger>
                <SelectValue :placeholder="column.placeholder || $t('common.select')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in getColumnOptions(column, item)"
                  :key="option.value"
                  :value="option.value"
                  :disabled="option.disabled"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <Input
              v-else
              v-model="item[column.key] as string"
              :name="column.key"
              :placeholder="column.placeholder"
              :disabled="column.readonly || !canEdit"
            />
          </TableCell>
          <TableCell class="flex items-center gap-1">
            <slot
              name="actions"
              :item="item"
              :index="index"
            />
            <Button
              v-if="canDelete"
              variant="ghost"
              size="icon"
              @click="removeItem(index)"
            >
              <Icon name="lucide:trash-2" />
              <span class="sr-only">{{ $t(removeButtonLabel) }}</span>
            </Button>
          </TableCell>
        </TableRow>
      </TableBody>
      <TableFooter>
        <TableRow
          v-if="showAddRow && canAdd"
          :id="`${id}-new-row`"
          class="hover:bg-transparent"
        >
          <TableCell v-if="allowSort" />
          <TableCell
            v-for="column in columns"
            :key="column.key"
          >
            <slot
              v-if="column.type === 'custom'"
              :name="`new-cell-${column.key}`"
              :item="newItem"
              :column="column"
            />
            <Switch
              v-else-if="column.type === 'switch'"
              v-model="newItem[column.key] as boolean"
              :disabled="column.readonly || !canEdit"
            />
            <Select
              v-else-if="column.type === 'select'"
              :model-value="getSelectValue(newItem, column.key)"
              :disabled="column.readonly || !canEdit"
              @update:model-value="(value) => updateSelectValue(newItem, column.key, value)"
            >
              <SelectTrigger>
                <SelectValue :placeholder="column.placeholder || $t('common.select')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in getColumnOptions(column, newItem)"
                  :key="option.value"
                  :value="option.value"
                  :disabled="option.disabled"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <Input
              v-else
              v-model="newItem[column.key] as string"
              :placeholder="column.placeholder"
              :disabled="!canEdit"
              @keydown.enter="addItem"
            />
          </TableCell>
          <TableCell class="text-right">
            <Button
              variant="ghost"
              size="icon"
              :disabled="!canAddItem"
              @click="addItem"
            >
              <Icon name="lucide:plus" />
              <span class="sr-only">{{ $t(addButtonLabel) }}</span>
            </Button>
          </TableCell>
        </TableRow>
      </TableFooter>
    </Table>
  </div>
</template>
