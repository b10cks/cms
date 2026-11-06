<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { FormField } from '~/components/ui/form'
import { InputGroup, InputGroupAddon, InputGroupInput } from '~/components/ui/input-group'
import { parsePriceAmount, resolvePriceCurrencies, type PriceValue } from '~/types/price'

const props = defineProps<{
  item: PriceSchema & { key: string }
  modelValue?: PriceValue | null
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: PriceValue | null]
}>()

const currencies = computed(() => resolvePriceCurrencies(props.item))

/** "DE-EUR" reads as region DE in EUR, "EUR" as a bare currency, anything else as its own label. */
const splitCode = (code: string): { region: string | null; currency: string | null } => {
  const regional = /^([a-z]{2,3})[-_ ]([a-z]{3})$/i.exec(code)
  if (regional) return { region: regional[1].toUpperCase(), currency: regional[2].toUpperCase() }
  if (/^[a-z]{3}$/i.test(code)) return { region: null, currency: code.toUpperCase() }
  return { region: code, currency: null }
}

const columns = computed(() => currencies.value.map((code) => ({ code, ...splitCode(code) })))

// String inputs per currency — keeps display state separate from the stored number value.
const inputs = ref<Record<string, string>>({})

const toInput = (val: unknown): string =>
  typeof val === 'number' && Number.isFinite(val) ? String(val) : ''

// Guard against re-emitting while syncing from the model (mirrors GeoBlock pattern).
let syncing = false

const syncFromModel = (value: PriceValue | null | undefined) => {
  syncing = true
  const next: Record<string, string> = {}
  for (const code of currencies.value) {
    next[code] = toInput(value?.[code])
  }
  inputs.value = next
  syncing = false
}

watch(
  () => [props.modelValue, currencies.value] as const,
  ([val]) => syncFromModel(val),
  {
    immediate: true,
    deep: true,
  }
)

// `raw` can be a number: Vue's vModelText casts `<input type="number">` values to JS numbers
// before emitting, so the update:modelValue payload is a number, not a string.
const handleInput = (code: string, raw: string | number) => {
  if (syncing) return
  inputs.value[code] = String(raw)
  const result: PriceValue = {}
  for (const c of currencies.value) {
    result[c] = parsePriceAmount(inputs.value[c] ?? '')
  }
  const allEmpty = Object.values(result).every((v) => v === null)
  emit('update:modelValue', allEmpty ? null : result)
}
</script>

<template>
  <FormField
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || undefined"
    :required="item.required"
  >
    <template #default="{ id }">
      <div class="grid gap-2">
        <InputGroup
          v-for="(column, index) in columns"
          :key="column.code"
        >
          <InputGroupAddon
            v-if="column.region"
            class="min-w-9 justify-start text-primary"
          >
            {{ column.region }}
          </InputGroupAddon>
          <InputGroupInput
            :id="index === 0 ? id : undefined"
            :model-value="inputs[column.code] ?? ''"
            :name="`${item.key}_${column.code}`"
            :aria-label="`${item.name || item.key}, ${column.code}`"
            type="number"
            inputmode="decimal"
            min="0"
            step="any"
            placeholder="–"
            :readonly="readOnly"
            class="text-right tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            @update:model-value="handleInput(column.code, $event)"
          />
          <InputGroupAddon
            v-if="column.currency"
            align="inline-end"
            class="text-input-muted"
          >
            {{ column.currency }}
          </InputGroupAddon>
        </InputGroup>
      </div>
    </template>
  </FormField>
</template>
