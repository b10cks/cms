<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { FormField } from '~/components/ui/form'

const serverLocations = [
  { id: 'eu', name: 'Europe (EU)', icon: 'flag:eu-4x3' },
  { id: 'us', name: 'United States (US)', icon: 'flag:us-4x3', disabled: false },
]

const selectedLocationId = defineModel<string>()

const selectedLocation = computed(() =>
  serverLocations.find((location) => location.id === selectedLocationId.value)
)
</script>

<template>
  <FormField
    name="location"
    :label="$t('labels.spaces.fields.location')"
    :description="$t('labels.spaces.fields.locationDescription')"
  >
    <Select
      v-model="selectedLocationId"
      v-bind="$attrs"
    >
      <SelectTrigger
        id="server-location"
        class="w-full"
      >
        <SelectValue :placeholder="$t('labels.spaces.fields.locationPlaceholder')">
          <div class="flex items-center gap-2">
            <Icon :name="selectedLocation?.icon" />
            <span>{{ selectedLocation?.name }}</span>
          </div>
        </SelectValue>
      </SelectTrigger>
      <SelectContent>
        <SelectItem
          v-for="location in serverLocations"
          :key="location.id"
          :value="location.id"
          :disabled="location.disabled"
        >
          <div class="flex items-center">
            <Icon
              :name="location.icon"
            />
            <span>{{ location.name }}</span>
          </div>
        </SelectItem>
      </SelectContent>
    </Select>
  </FormField>
</template>
