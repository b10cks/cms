<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useSortable } from '@vueuse/integrations/useSortable'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
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
  type?: 'text' | 'switch' | 'custom'
  width?: string
  placeholder?: string
  required?: boolean
  readonly?: boolean
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
    newItemTemplate?: TableItem
    addButtonLabel?: string
    removeButtonLabel?: string
  }>(),
  {
    allowSort: false,
    showAddRow: true,
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
  useSortable(tableBodyRef, localItems, {
    handle: '.sort-handle',
    animation: 150,
    onEnd: () => {
      nextTick(() => emit('update:items', [...localItems.value]))
    },
  })
}

const canAddItem = computed(() => {
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
              :disabled="column.readonly"
            />
            <Input
              v-else
              v-model="item[column.key] as string"
              :name="column.key"
              :placeholder="column.placeholder"
              :disabled="column.readonly"
            />
          </TableCell>
          <TableCell class="flex items-center gap-1">
            <slot
              name="actions"
              :item="item"
              :index="index"
            />
            <Button
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
          v-if="showAddRow"
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
              :disabled="column.readonly"
            />
            <Input
              v-else
              v-model="newItem[column.key] as string"
              :placeholder="column.placeholder"
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
