<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { SplitBadge } from '~/components/ui/badge'

interface FilterableItem {
  value: string | number
  label: string
}

interface FilterableOperator {
  value:
    | 'null'
    | '!null'
    | 'eq'
    | 'neq'
    | 'empty'
    | '!empty'
    | 'like'
    | '!like'
    | '^like'
    | 'like$'
    | 'lt'
    | 'gt'
    | 'lte'
    | 'gte'
    | 'in'
    | '!in'
  label: string
}

export interface FilterableField {
  id: string
  label: string
  items?: FilterableItem[]
  operators?: FilterableOperator[]
  datepicker?: {
    max?: string
    min?: string
  }
}

interface FilterValue {
  field: string
  fieldLabel: string
  operator?: string
  operatorLabel?: string
  value: string | number
  valueLabel?: string
}

const props = defineProps<{
  modelValue?: Record<string, unknown>
  filterableFields: FilterableField[]
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: Record<string, unknown>): void
  (e: 'search', value: string): void
  (e: 'reset'): void
}>()

const { $t } = useI18n()

// Operators that carry no additional value — the operator itself is the complete filter
const NO_VALUE_OPERATORS = ['null', '!null', 'empty', '!empty']

const instanceId = useId()
const dropdownId = `${instanceId}-dropdown`

const inputValue = ref('')
const dropdownOpen = ref(false)
const activeFilters = ref<FilterValue[]>([])
const stage = ref<'field' | 'operator' | 'value'>('field')
const selectedField = ref<FilterableField | null>(null)
const selectedOperator = ref<FilterableOperator | null>(null)
const containerRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)
const dateValue = ref('')
const selectedDropdownIndex = ref(0)
const dropdownRef = ref<HTMLDivElement | null>(null)
const editingFilterIndex = ref<number | null>(null)
const srMessage = ref('')
const lastSearch = ref('')

const placeholder = computed(() => {
  if (stage.value === 'field') {
    return String($t('labels.search.searchOrSelectField'))
  } else if (stage.value === 'operator') {
    return String(
      $t('labels.search.selectOperatorFor', { field: String(selectedField.value?.label || '') })
    )
  } else if (stage.value === 'value') {
    if (selectedField.value?.items && selectedField.value.items.length > 0) {
      return String(
        $t('labels.search.selectValueFor', { field: String(selectedField.value.label) })
      )
    } else if (selectedField.value?.datepicker) {
      return String($t('labels.search.enterDateFor', { field: String(selectedField.value.label) }))
    } else {
      return String(
        $t('labels.search.enterValueFor', { field: String(selectedField.value?.label || '') })
      )
    }
  }
  return String($t('labels.search.search'))
})

const matchesInput = (label: string): boolean =>
  !inputValue.value || label.toLowerCase().includes(inputValue.value.toLowerCase())

const dropdownItems = computed((): (FilterableField | FilterableOperator | FilterableItem)[] => {
  if (stage.value === 'field') {
    // Fields already used by another filter are unavailable; the filter being
    // edited doesn't block its own field
    const usedFields = new Set(
      activeFilters.value
        .filter((_, index) => index !== editingFilterIndex.value)
        .map((filter) => filter.field)
    )

    return props.filterableFields.filter(
      (field) => !usedFields.has(field.id) && matchesInput(String(field.label))
    )
  } else if (stage.value === 'operator' && selectedField.value?.operators) {
    return selectedField.value.operators.filter((operator) =>
      matchesInput(String(operator.label))
    )
  } else if (stage.value === 'value' && selectedField.value?.items) {
    return selectedField.value.items.filter((item) => matchesInput(String(item.label)))
  }
  return []
})

watch(dropdownItems, () => {
  selectedDropdownIndex.value = 0
})

const serializeFilters = (filters: FilterValue[]): Record<string, string | number> => {
  const result: Record<string, string | number> = {}

  filters.forEach((filter) => {
    if (filter.operator) {
      result[filter.field] = `${filter.operator}:${filter.value}`
    } else {
      result[filter.field] = filter.value
    }
  })

  return result
}

const parseModelValue = (model: Record<string, unknown>): FilterValue[] => {
  const filters: FilterValue[] = []

  for (const [key, raw] of Object.entries(model)) {
    if (key === 'q' || raw === null || raw === undefined) continue

    const field = props.filterableFields.find((f) => f.id === key)
    if (!field) continue

    let operator: FilterableOperator | undefined
    let value: string | number = raw as string | number

    if (typeof raw === 'string' && raw.includes(':') && field.operators?.length) {
      const prefix = raw.slice(0, raw.indexOf(':'))
      const matched = field.operators.find((op) => String(op.value) === prefix)
      if (matched) {
        operator = matched
        value = raw.slice(prefix.length + 1)
      }
    }

    const item = field.items?.find((i) => String(i.value) === String(value))

    filters.push({
      field: field.id,
      fieldLabel: String(field.label),
      operator: operator ? String(operator.value) : undefined,
      operatorLabel: operator ? String(operator.label) : undefined,
      value: item ? item.value : value,
      valueLabel: item ? String(item.label) : String(value),
    })
  }

  return filters
}

const sameFilters = (
  a: Record<string, string | number>,
  b: Record<string, string | number>
): boolean => {
  const keysA = Object.keys(a)
  return (
    keysA.length === Object.keys(b).length && keysA.every((key) => String(a[key]) === String(b[key]))
  )
}

// Keep activeFilters in sync with externally set modelValue (e.g. restored from URL)
watch(
  () => props.modelValue,
  (model) => {
    const incoming = { ...model } as Record<string, string | number>
    delete incoming.q
    if (!sameFilters(serializeFilters(activeFilters.value), incoming)) {
      activeFilters.value = parseModelValue(incoming)
    }
  },
  { immediate: true, deep: true }
)

const emitFilters = (): void => {
  emit('update:modelValue', serializeFilters(activeFilters.value))
}

const pendingFilter = computed((): Pick<
  FilterValue,
  'field' | 'fieldLabel' | 'operator' | 'operatorLabel'
> | null => {
  if (!selectedField.value) return null

  return {
    field: selectedField.value.id,
    fieldLabel: String(selectedField.value.label),
    operator: selectedOperator.value?.value ? String(selectedOperator.value.value) : undefined,
    operatorLabel: selectedOperator.value?.label ? String(selectedOperator.value.label) : undefined,
  }
})

const announceToScreenReader = (message: string): void => {
  // Clear first so repeating the same message is re-announced
  srMessage.value = ''
  nextTick(() => {
    srMessage.value = String(message)
  })
}

const focusInput = (): void => {
  nextTick(() => {
    inputRef.value?.focus()
  })
}

const resetSelectionState = (): void => {
  selectedField.value = null
  selectedOperator.value = null
  stage.value = 'field'
  inputValue.value = ''
  dateValue.value = ''
  selectedDropdownIndex.value = 0
  editingFilterIndex.value = null
}

const clearAllFilters = (): void => {
  activeFilters.value = []
  resetSelectionState()
  dropdownOpen.value = false
  lastSearch.value = ''
  emit('update:modelValue', {})
  announceToScreenReader($t('labels.search.allFiltersCleared'))
  focusInput()
  emit('reset')
}

const scrollToSelected = (): void => {
  nextTick(() => {
    const selectedEl = dropdownRef.value?.querySelector(
      `#${dropdownId}-item-${selectedDropdownIndex.value}`
    )
    selectedEl?.scrollIntoView({ block: 'nearest' })
  })
}

const handleFieldSelect = (field: FilterableField): void => {
  selectedField.value = field
  inputValue.value = ''
  stage.value = field.operators && field.operators.length > 0 ? 'operator' : 'value'
  dropdownOpen.value = true
  selectedDropdownIndex.value = 0
  focusInput()

  announceToScreenReader(String($t('labels.search.fieldSelected', { field: String(field.label) })))
}

const handleOperatorSelect = (operator: FilterableOperator): void => {
  selectedOperator.value = operator
  inputValue.value = ''

  if (NO_VALUE_OPERATORS.includes(String(operator.value))) {
    handleValueSelect()
    return
  }

  stage.value = 'value'
  dropdownOpen.value = true
  selectedDropdownIndex.value = 0
  focusInput()

  announceToScreenReader(
    String($t('labels.search.operatorSelected', { operator: String(operator.label) }))
  )
}

const handleValueSelect = (item?: FilterableItem): void => {
  if (!selectedField.value) return

  const newFilter: FilterValue = {
    field: selectedField.value.id,
    fieldLabel: String(selectedField.value.label),
    value: '',
  }

  if (selectedOperator.value) {
    newFilter.operator = String(selectedOperator.value.value)
    newFilter.operatorLabel = String(selectedOperator.value.label)
  }

  if (item) {
    newFilter.value = item.value
    newFilter.valueLabel = String(item.label)
  } else if (dateValue.value && selectedField.value.datepicker) {
    newFilter.value = dateValue.value
    newFilter.valueLabel = dateValue.value
  } else {
    newFilter.value = inputValue.value
    newFilter.valueLabel = inputValue.value
  }

  const isNoValueOperator = newFilter.operator && NO_VALUE_OPERATORS.includes(newFilter.operator)
  const hasValue = String(newFilter.value ?? '').trim() !== ''

  // Without a value there is nothing to commit; keep the pending selection
  // instead of throwing the user's field/operator choice away
  if (!isNoValueOperator && !hasValue) return

  if (editingFilterIndex.value === null) {
    activeFilters.value.push(newFilter)
    announceToScreenReader(
      $t('labels.search.filterAdded', {
        field: newFilter.fieldLabel,
        operator: newFilter.operatorLabel || '',
        value: newFilter.valueLabel || newFilter.value,
      })
    )
  } else {
    activeFilters.value[editingFilterIndex.value] = newFilter
    announceToScreenReader(
      $t('labels.search.filterUpdated', {
        field: newFilter.fieldLabel,
        operator: newFilter.operatorLabel || '',
        value: newFilter.valueLabel || newFilter.value,
      })
    )
  }
  emitFilters()

  resetSelectionState()
  dropdownOpen.value = false
}

const handleDateSelect = (): void => {
  if (dateValue.value) {
    handleValueSelect()
  }
}

const removeFilter = (index: number): void => {
  const filter = activeFilters.value[index]
  activeFilters.value.splice(index, 1)

  if (editingFilterIndex.value !== null && index < editingFilterIndex.value) {
    editingFilterIndex.value--
  }

  emitFilters()

  announceToScreenReader(
    $t('labels.search.filterRemoved', {
      field: filter.fieldLabel,
    })
  )
}

const editFilter = (index: number): void => {
  const filter = activeFilters.value[index]
  editingFilterIndex.value = index

  const field = props.filterableFields.find((f) => f.id === filter.field)
  if (!field) return

  selectedField.value = field

  if (filter.operator && field.operators) {
    const operator = field.operators.find((op) => String(op.value) === filter.operator)
    if (operator) {
      selectedOperator.value = operator
      stage.value = 'value'
    } else {
      stage.value = 'operator'
    }
  } else {
    stage.value = 'value'
  }

  if (stage.value === 'value') {
    if (field.datepicker) {
      dateValue.value = String(filter.value)
    } else if (field.items) {
      inputValue.value = ''
    } else {
      inputValue.value = String(filter.value)
    }
  }

  dropdownOpen.value = true
  selectedDropdownIndex.value = 0

  nextTick(() => {
    inputRef.value?.focus()
    announceToScreenReader(
      $t('labels.search.editingFilter', {
        field: filter.fieldLabel,
      })
    )
  })
}

const cancelPendingFilter = (): void => {
  resetSelectionState()
  dropdownOpen.value = false
  announceToScreenReader($t('labels.search.filteringCancelled'))
}

// Commit a typed/picked value if there is one, otherwise abandon the pending
// selection — used when focus/attention leaves the component
const settlePendingFilter = (): void => {
  if (stage.value === 'value' && (inputValue.value || dateValue.value)) {
    handleValueSelect()
  } else if (stage.value !== 'field' || editingFilterIndex.value !== null) {
    resetSelectionState()
  }
  dropdownOpen.value = false
}

const handleInputFocus = (): void => {
  dropdownOpen.value = true
}

const handleInputChange = (): void => {
  dropdownOpen.value = true

  // Backspacing the search text to empty should clear stale results
  if (stage.value === 'field' && inputValue.value === '' && lastSearch.value !== '') {
    lastSearch.value = ''
    emit('search', '')
  }
}

const handleKeyDown = (e: KeyboardEvent): void => {
  if (dropdownOpen.value && dropdownItems.value.length > 0) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      selectedDropdownIndex.value = (selectedDropdownIndex.value + 1) % dropdownItems.value.length
      scrollToSelected()
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      selectedDropdownIndex.value =
        selectedDropdownIndex.value <= 0
          ? dropdownItems.value.length - 1
          : selectedDropdownIndex.value - 1
      scrollToSelected()
    }
  }

  if (e.key === 'Enter') {
    e.preventDefault()

    if (dropdownOpen.value && dropdownItems.value.length > 0) {
      const selectedItem = dropdownItems.value[selectedDropdownIndex.value]

      if (stage.value === 'field') {
        handleFieldSelect(selectedItem as FilterableField)
      } else if (stage.value === 'operator') {
        handleOperatorSelect(selectedItem as FilterableOperator)
      } else if (stage.value === 'value') {
        handleValueSelect(selectedItem as FilterableItem)
      }
    } else if (stage.value === 'value') {
      handleValueSelect()
    } else if (stage.value === 'field' && inputValue.value) {
      lastSearch.value = inputValue.value
      emit('search', inputValue.value)
    }
  }

  if (e.key === 'Backspace' && inputValue.value === '') {
    if (stage.value === 'value') {
      e.preventDefault()
      stage.value = selectedField.value?.operators?.length ? 'operator' : 'field'
      selectedOperator.value = null
      dateValue.value = ''
      if (stage.value === 'field') selectedField.value = null
      dropdownOpen.value = true
      announceToScreenReader($t('labels.search.goingBack'))
    } else if (stage.value === 'operator') {
      e.preventDefault()
      stage.value = 'field'
      selectedField.value = null
      dropdownOpen.value = true
      announceToScreenReader($t('labels.search.goingBack'))
    } else if (editingFilterIndex.value === null && activeFilters.value.length > 0) {
      e.preventDefault()
      removeFilter(activeFilters.value.length - 1)
    }
  }

  if (e.key === 'Escape') {
    e.preventDefault()

    if (stage.value !== 'field' || editingFilterIndex.value !== null) {
      cancelPendingFilter()
    } else if (dropdownOpen.value) {
      dropdownOpen.value = false
    } else if (inputValue.value) {
      inputValue.value = ''
      if (lastSearch.value !== '') {
        lastSearch.value = ''
        emit('search', '')
      }
    } else {
      emit('reset')
    }
  }

  // Let focus move on, but don't leave a pending filter or open dropdown behind
  if (e.key === 'Tab') {
    settlePendingFilter()
  }
}

const handleClickOutside = (event: MouseEvent): void => {
  if (containerRef.value && !containerRef.value.contains(event.target as Node)) {
    settlePendingFilter()
  }
}

onMounted(() => {
  document.addEventListener('mousedown', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', handleClickOutside)
})
</script>

<template>
  <div
    ref="containerRef"
    class="relative w-full"
  >
    <div
      class="flex min-h-9 flex-wrap items-center gap-2 rounded-md bg-input py-1 pr-2 pl-2 text-primary shadow-sm transition-colors placeholder:text-muted focus-within:ring-1 focus-within:ring-ring focus-within:outline-none disabled:cursor-not-allowed disabled:opacity-50"
    >
      <!-- Applied Filters as Badges; the one being edited is represented by the pending badge -->
      <template
        v-for="(filter, index) in activeFilters"
        :key="`filter-${index}`"
      >
        <SplitBadge
          v-if="index !== editingFilterIndex"
          :label="filter.fieldLabel"
          removable
          :aria-label="
            $t('labels.search.removeFilter', {
              field: filter.fieldLabel,
              operator: filter.operatorLabel || '',
              value: filter.valueLabel || filter.value,
            })
          "
          @remove="removeFilter(index)"
          @click="editFilter(index)"
        >
          {{ filter.operatorLabel ? `${filter.operatorLabel} ` : '' }}
          {{ filter.valueLabel || filter.value }}
        </SplitBadge>
      </template>

      <SplitBadge
        v-if="pendingFilter"
        :label="pendingFilter.fieldLabel"
      >
        {{ pendingFilter.operatorLabel ? `${pendingFilter.operatorLabel} ` : '' }}
      </SplitBadge>

      <div class="relative min-w-20 flex-1">
        <input
          ref="inputRef"
          v-model="inputValue"
          type="text"
          role="combobox"
          class="w-full border-none bg-transparent p-1 text-sm font-semibold text-primary focus:outline-none"
          :placeholder="String(placeholder)"
          :aria-label="String(placeholder)"
          :aria-expanded="dropdownOpen"
          aria-haspopup="listbox"
          :aria-autocomplete="dropdownItems.length > 0 ? 'list' : 'none'"
          :aria-controls="dropdownOpen ? dropdownId : undefined"
          :aria-activedescendant="
            dropdownOpen && dropdownItems.length > 0
              ? `${dropdownId}-item-${selectedDropdownIndex}`
              : undefined
          "
          @focus="handleInputFocus"
          @input="handleInputChange"
          @keydown="handleKeyDown"
        />
      </div>

      <button
        v-if="activeFilters.length > 0 || inputValue"
        type="button"
        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full p-0.5 hover:bg-elevated focus:ring-2 focus:ring-ring focus:outline-none"
        :aria-label="String($t('labels.search.clearAllFilters'))"
        @click="clearAllFilters"
      >
        <Icon
          name="lucide:x"
          class="text-muted"
        />
      </button>
    </div>

    <div
      v-if="dropdownOpen"
      :id="dropdownId"
      ref="dropdownRef"
      class="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-md bg-input p-1 shadow-lg"
      role="listbox"
      :aria-label="String($t(`labels.search.${stage}DropdownLabel`))"
    >
      <template v-if="dropdownItems.length > 0">
        <div
          v-for="(item, index) in dropdownItems"
          :id="`${dropdownId}-item-${index}`"
          :key="`dropdown-item-${index}`"
          :class="[
            'relative flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-semibold transition-colors outline-none select-none hover:bg-blue-600 hover:text-primary focus:bg-blue-600 focus:text-primary data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&>svg]:size-4 [&>svg]:shrink-0',
            index === selectedDropdownIndex && 'bg-blue-600 text-primary',
          ]"
          role="option"
          :aria-selected="index === selectedDropdownIndex"
          @click="
            stage === 'field'
              ? handleFieldSelect(item as FilterableField)
              : stage === 'operator'
                ? handleOperatorSelect(item as FilterableOperator)
                : handleValueSelect(item as FilterableItem)
          "
        >
          {{ item.label }}
        </div>
      </template>

      <div
        v-else-if="stage === 'value' && selectedField?.datepicker"
        class="p-4"
      >
        <label
          :for="`${dropdownId}-date-input`"
          class="sr-only"
          >{{ $t('labels.search.dateInput') }}</label
        >
        <input
          :id="`${dropdownId}-date-input`"
          v-model="dateValue"
          type="date"
          class="w-full rounded border border-gray-600 bg-elevated p-2 text-primary"
          :max="selectedField.datepicker.max?.split('T')[0]"
          :min="selectedField.datepicker.min?.split('T')[0]"
          :aria-label="
            String($t('labels.search.enterDateFor', { field: String(selectedField.label) }))
          "
          @change="handleDateSelect"
        />
      </div>

      <div
        v-else-if="stage === 'value'"
        class="px-4 py-2 text-sm font-semibold text-muted select-none"
        role="status"
      >
        {{ $t('labels.search.typeValueAndEnter') }}
      </div>

      <div
        v-else
        class="px-4 py-2 text-sm font-semibold text-muted select-none"
        role="status"
      >
        {{ $t('labels.search.noResultsFound') }}
      </div>
    </div>

    <div
      class="sr-only"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {{ srMessage }}
    </div>

    <div
      class="sr-only"
      role="status"
      aria-live="polite"
    >
      {{ dropdownOpen ? $t('labels.search.keyboardNavHint') : '' }}
    </div>
  </div>
</template>
