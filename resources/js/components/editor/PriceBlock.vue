<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { InputField, Label } from '~/components/ui/form'
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
  <div class="grid gap-2">
    <Label
      :label="item.name || item.key"
      :required="item.required"
    />

    <p
      v-if="item.description"
      class="text-muted-foreground text-xs"
    >
      {{ item.description }}
    </p>

    <div class="space-y-4 border-l-1 border-l-border pl-3">
      <InputField
        v-for="code in currencies"
        :key="code"
        :model-value="inputs[code] ?? ''"
        :name="`${item.key}_${code}`"
        :label="code"
        type="number"
        min="0"
        step="any"
        :readonly="readOnly"
        @update:model-value="handleInput(code, $event)"
      />
    </div>
  </div>
</template>
