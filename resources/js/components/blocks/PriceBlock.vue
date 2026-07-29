<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { FormField, InputField } from '~/components/ui/form'

const props = defineProps<{ value: PriceSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const { $t } = useI18n()

const newCurrency = ref('')

// Allow plain ISO codes (EUR) and region-prefixed codes (US-USD). Strip anything
// but A–Z and hyphens, and collapse a leading/duplicate hyphen so `-USD` can't slip through.
const normalise = (code: string) =>
  code.trim().toUpperCase().replace(/[^A-Z-]/g, '').replace(/^-+/, '').replace(/-+/g, '-').slice(0, 7)

const updateBaseCurrency = (raw: string) => {
  emit('update:item-value', 'base_currency', normalise(raw))
}

const currencies = computed<string[]>(() => props.value.currencies ?? [])

const addCurrency = () => {
  const code = normalise(newCurrency.value)
  if (!code || code === props.value.base_currency || currencies.value.includes(code)) {
    newCurrency.value = ''
    return
  }
  emit('update:item-value', 'currencies', [...currencies.value, code])
  newCurrency.value = ''
}

const removeCurrency = (code: string) => {
  emit('update:item-value', 'currencies', currencies.value.filter((c) => c !== code))
}

const handleNewCurrencyKey = (event: KeyboardEvent) => {
  if (event.key === 'Enter') {
    event.preventDefault()
    addCurrency()
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <InputField
      name="base_currency"
      :model-value="value.base_currency"
      :label="$t('labels.blocks.fields.price.baseCurrency')"
      :description="$t('labels.blocks.fields.price.baseCurrencyDescription')"
      maxlength="7"
      placeholder="EUR"
      @update:model-value="updateBaseCurrency"
    />

    <FormField
      name="currencies"
      :label="$t('labels.blocks.fields.price.currencies')"
      :description="$t('labels.blocks.fields.price.currenciesDescription')"
    >
      <div class="flex flex-col gap-2">
        <div
          v-if="currencies.length"
          class="flex flex-wrap gap-2"
        >
          <span
            v-for="code in currencies"
            :key="code"
            class="flex items-center gap-1 rounded-md border border-input bg-elevated px-2 py-1 text-sm font-mono"
          >
            {{ code }}
            <button
              type="button"
              class="text-muted-foreground hover:text-foreground ml-1"
              :aria-label="$t('actions.remove')"
              @click="removeCurrency(code)"
            >
              <Icon
                name="lucide:x"
                class="size-3"
              />
            </button>
          </span>
        </div>

        <div class="flex gap-2">
          <Input
            v-model="newCurrency"
            :placeholder="$t('labels.blocks.fields.price.currenciesPlaceholder')"
            maxlength="7"
            class="w-28 font-mono uppercase"
            @keydown="handleNewCurrencyKey"
          />
          <Button
            type="button"
            size="sm"
            variant="outline"
            :disabled="!newCurrency.trim()"
            :aria-label="$t('actions.add')"
            @click="addCurrency"
          >
            <Icon name="lucide:plus" />
          </Button>
        </div>
      </div>
    </FormField>
  </div>
</template>
