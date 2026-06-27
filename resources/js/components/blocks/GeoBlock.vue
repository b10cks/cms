<script setup lang="ts">
import { computed } from 'vue'

import { CheckboxField, SelectField } from '~/components/ui/form'

defineProps<{ value: GeoSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const { $t } = useI18n()

const keyStyleOptions = computed(() => [
  { value: 'lat_lng' as GeoKeyStyle, label: $t('labels.blocks.fields.geo.keyStyleLatLng') },
  { value: 'lat_lon' as GeoKeyStyle, label: $t('labels.blocks.fields.geo.keyStyleLatLon') },
  {
    value: 'latitude_longitude' as GeoKeyStyle,
    label: $t('labels.blocks.fields.geo.keyStyleLatitudeLongitude'),
  },
])
</script>

<template>
  <div class="flex flex-col gap-6">
    <SelectField
      name="key_style"
      :model-value="value.key_style || 'lat_lng'"
      :label="$t('labels.blocks.fields.geo.keyStyle')"
      :description="$t('labels.blocks.fields.geo.keyStyleDescription')"
      :options="keyStyleOptions"
      @update:model-value="emit('update:item-value', 'key_style', $event)"
    />

    <CheckboxField
      :model-value="value.altitude"
      name="altitude"
      :label="$t('labels.blocks.fields.geo.altitude')"
      :description="$t('labels.blocks.fields.geo.altitudeDescription')"
      @update:model-value="emit('update:item-value', 'altitude', $event)"
    />

    <CheckboxField
      :model-value="value.map ?? true"
      name="map"
      :label="$t('labels.blocks.fields.geo.map')"
      :description="$t('labels.blocks.fields.geo.mapDescription')"
      @update:model-value="emit('update:item-value', 'map', $event)"
    />
  </div>
</template>
