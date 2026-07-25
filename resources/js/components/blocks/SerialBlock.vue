<script setup lang="ts">
import { computed } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField, FormField, InputField, SelectField } from '~/components/ui/form'

const props = defineProps<{ value: SerialSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const { $t } = useI18n()

/**
 * Kept in step with the token resolvers registered in ContentServiceProvider.
 * The sample is what the token renders for the worked example below, so the
 * preview stays honest without a round-trip to the server.
 */
const tokens = [
  { token: '{counter}', sample: '4' },
  { token: '{counter:3}', sample: '004' },
  { token: '{parent:key}', sample: '1' },
  { token: '{ancestor:key}', sample: '1' },
  { token: '{field:key}', sample: 'chalet' },
  { token: '{block}', sample: 'house' },
  { token: '{date:Y}', sample: String(new Date().getFullYear()) },
  { token: '{lang}', sample: 'en' },
]

const scopeDimensions: SerialScopeDimension[] = [
  'block',
  'parent',
  'language',
  'year',
  'month',
  'space',
]

const format = computed(() => props.value.format || '{counter}')
const scope = computed<SerialScopeDimension[]>(() => props.value.scope ?? ['block', 'parent'])

const uniqueOptions = computed(() => [
  { value: 'scope', label: $t('labels.blocks.fields.serial.uniqueScope') },
  { value: 'block', label: $t('labels.blocks.fields.serial.uniqueBlock') },
  { value: 'space', label: $t('labels.blocks.fields.serial.uniqueSpace') },
  { value: 'none', label: $t('labels.blocks.fields.serial.uniqueNone') },
])

const onMoveOptions = computed(() => [
  { value: 'keep', label: $t('labels.blocks.fields.serial.onMoveKeep') },
  { value: 'reallocate', label: $t('labels.blocks.fields.serial.onMoveReallocate') },
])

/**
 * A rough render of the current format. Ancestor and field tokens cannot be
 * resolved without a real entry, so they stand in with placeholder text — this
 * shows the shape of the identifier, not its value.
 */
const sample = computed(() =>
  format.value.replace(/\{([a-z_]+)(?::([^}]*))?\}/g, (match, name: string, argument?: string) => {
    if (name === 'counter') {
      const padding = Number(argument)
      return padding > 0 ? String(4).padStart(padding, '0') : '4'
    }
    if (name === 'date') return new Date().getFullYear().toString()
    if (name === 'block') return 'house'
    if (name === 'lang') return 'en'
    if (name === 'parent' || name === 'ancestor') return '1'
    if (name === 'field') return argument ?? 'value'
    return match
  })
)

const hasCounter = computed(() => /\{counter(?::[^}]*)?\}/.test(format.value))

const appendToken = (token: string) => {
  emit('update:item-value', 'format', `${format.value}${token}`)
}

const toggleScope = (dimension: SerialScopeDimension, enabled: boolean) => {
  const next = enabled
    ? [...scope.value, dimension]
    : scope.value.filter((entry) => entry !== dimension)

  // An empty scope would mean one counter for the whole space, which is almost
  // never what someone unticking the last box intends.
  emit('update:item-value', 'scope', next.length ? next : ['block'])
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <InputField
      name="format"
      :model-value="format"
      :label="$t('labels.blocks.fields.serial.format')"
      :description="$t('labels.blocks.fields.serial.formatDescription')"
      class="font-mono"
      @update:model-value="emit('update:item-value', 'format', $event)"
    />

    <FormField
      name="format_tokens"
      :label="$t('labels.blocks.fields.serial.tokens')"
      :description="$t('labels.blocks.fields.serial.tokensDescription')"
    >
      <div class="flex flex-col gap-3">
        <div class="flex flex-wrap gap-2">
          <Button
            v-for="entry in tokens"
            :key="entry.token"
            type="button"
            size="sm"
            variant="outline"
            class="font-mono"
            @click="appendToken(entry.token)"
          >
            <Icon name="lucide:plus" />
            {{ entry.token }}
          </Button>
        </div>

        <p class="text-sm text-muted">
          {{ $t('labels.blocks.fields.serial.samplePrefix') }}
          <span class="font-mono text-foreground">{{ sample }}</span>
        </p>

        <p
          v-if="!hasCounter"
          class="flex items-center gap-2 text-sm text-warning"
        >
          <Icon name="lucide:triangle-alert" />
          {{ $t('labels.blocks.fields.serial.counterRequired') }}
        </p>
      </div>
    </FormField>

    <FormField
      name="scope"
      :label="$t('labels.blocks.fields.serial.scope')"
      :description="$t('labels.blocks.fields.serial.scopeDescription')"
    >
      <div class="flex flex-col gap-2">
        <CheckboxField
          v-for="dimension in scopeDimensions"
          :key="dimension"
          :name="`scope_${dimension}`"
          :model-value="scope.includes(dimension)"
          :label="$t(`labels.blocks.fields.serial.scope_${dimension}`)"
          @update:model-value="toggleScope(dimension, Boolean($event))"
        />
      </div>
    </FormField>

    <SelectField
      name="unique"
      :model-value="value.unique || 'scope'"
      :label="$t('labels.blocks.fields.serial.unique')"
      :description="$t('labels.blocks.fields.serial.uniqueDescription')"
      :options="uniqueOptions"
      @update:model-value="emit('update:item-value', 'unique', $event)"
    />

    <SelectField
      name="on_move"
      :model-value="value.on_move || 'keep'"
      :label="$t('labels.blocks.fields.serial.onMove')"
      :description="$t('labels.blocks.fields.serial.onMoveDescription')"
      :options="onMoveOptions"
      @update:model-value="emit('update:item-value', 'on_move', $event)"
    />

    <CheckboxField
      :model-value="value.editable"
      name="editable"
      :label="$t('labels.blocks.fields.serial.editable')"
      :description="$t('labels.blocks.fields.serial.editableDescription')"
      @update:model-value="emit('update:item-value', 'editable', $event)"
    />
  </div>
</template>
