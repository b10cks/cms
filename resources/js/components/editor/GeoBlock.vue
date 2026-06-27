<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { InputField, Label } from '~/components/ui/form';
import { GEO_BOUNDS, GEO_TILE_URL, resolveGeoKeys, type GeoValue } from '~/types/geo';

const props = defineProps<{
  item: GeoSchema & { key: string }
  modelValue?: GeoValue | null
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: GeoValue | null]
}>()

const { t } = useI18n()

const keys = computed(() => resolveGeoKeys(props.item.key_style))
const showAltitude = computed(() => Boolean(props.item.altitude))
const showMap = computed(() => props.item.map ?? true)

// Inputs are kept as strings so the fields can be cleared; parsed to numbers on emit.
const lat = ref('')
const lon = ref('')
const alt = ref('')

const toInput = (value: unknown): string =>
  typeof value === 'number' && Number.isFinite(value) ? String(value) : ''

const toNumber = (value: string): number | null => {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? parsed : null
}

// Guards the input <-> map two-way sync against feedback loops.
let syncing = false

const buildValue = (): GeoValue | null => {
  const latitude = toNumber(lat.value)
  const longitude = toNumber(lon.value)

  if (latitude === null && longitude === null) return null

  const value: GeoValue = {
    [keys.value.lat]: latitude,
    [keys.value.lon]: longitude,
  }

  if (showAltitude.value) {
    value[keys.value.alt] = toNumber(alt.value)
  }

  return value
}

const handleInput = () => {
  if (syncing) return
  emit('update:modelValue', buildValue())
  updateMarker()
}

/* ---------------------------------------------------------------------------
 * Optional Leaflet map picker — loaded lazily, and only when `item.map` is on.
 * ------------------------------------------------------------------------- */
const mapEl = ref<HTMLElement | null>(null)
const map = shallowRef<any>(null)
const marker = shallowRef<any>(null)
const leaflet = shallowRef<any>(null)

const currentLatLng = (): [number, number] | null => {
  const latitude = toNumber(lat.value)
  const longitude = toNumber(lon.value)
  if (latitude === null || longitude === null) return null
  return [latitude, longitude]
}

const updateMarker = () => {
  const L = leaflet.value
  if (!L || !map.value) return

  const latlng = currentLatLng()
  if (!latlng) {
    if (marker.value) {
      map.value.removeLayer(marker.value)
      marker.value = null
    }
    return
  }

  if (marker.value) {
    marker.value.setLatLng(latlng)
  } else {
    marker.value = L.marker(latlng, {
      draggable: !props.readOnly,
      icon: L.divIcon({
        className: 'geo-marker',
        html: '<span class="block size-3 rounded-full bg-black ring-2 ring-white shadow"></span>',
        iconSize: [12, 12],
        iconAnchor: [6, 6],
      }),
    }).addTo(map.value)

    marker.value.on('dragend', () => {
      const { lat: mLat, lng: mLng } = marker.value.getLatLng()
      setFromMap(mLat, mLng)
    })
  }

  map.value.panTo(latlng)
}

watch(
  () => props.modelValue,
  (value) => {
    syncing = true
    lat.value = toInput(value?.[keys.value.lat])
    lon.value = toInput(value?.[keys.value.lon])
    alt.value = toInput(value?.[keys.value.alt])
    syncing = false
    updateMarker()
  },
  { immediate: true, deep: true }
)

const setFromMap = (latitude: number, longitude: number) => {
  lat.value = String(Number(latitude.toFixed(6)))
  lon.value = String(Number(longitude.toFixed(6)))
  emit('update:modelValue', buildValue())
}

const initMap = async () => {
  if (!showMap.value || !mapEl.value || map.value) return

  const mod = await import('leaflet')
  const L = (mod as any).default ?? mod
  await import('leaflet/dist/leaflet.css')
  leaflet.value = L

  const start = currentLatLng() ?? [0, 0]
  map.value = L.map(mapEl.value, { attributionControl: true }).setView(
    start,
    currentLatLng() ? 13 : 1
  )

  L.tileLayer(GEO_TILE_URL, {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(map.value)

  if (!props.readOnly) {
    map.value.on('click', (event: any) => setFromMap(event.latlng.lat, event.latlng.lng))
  }

  updateMarker()
}

onMounted(() => {
  if (showMap.value) initMap()
})

watch(showMap, (enabled) => {
  if (enabled) {
    // Wait for the container to render before initialising.
    requestAnimationFrame(() => initMap())
  } else if (map.value) {
    map.value.remove()
    map.value = null
    marker.value = null
  }
})

onBeforeUnmount(() => {
  if (map.value) {
    map.value.remove()
    map.value = null
  }
})
</script>

<template>
  <div class="grid gap-2">
    <Label
      :label="item.name || item.key"
      :required="item.required"
    />

    <div
      v-if="showMap"
      ref="mapEl"
      class="h-64 w-full overflow-hidden rounded-lg border border-input bg-surface"
    />

    <div class="grid grid-cols-2 gap-3">
      <InputField
        v-model="lat"
        :name="`${item.key}_lat`"
        :label="t('labels.geo.field.latitude')"
        type="number"
        step="any"
        :min="GEO_BOUNDS.lat.min"
        :max="GEO_BOUNDS.lat.max"
        :readonly="readOnly"
        @update:model-value="handleInput"
      />
      <InputField
        v-model="lon"
        :name="`${item.key}_lon`"
        :label="t('labels.geo.field.longitude')"
        type="number"
        step="any"
        :min="GEO_BOUNDS.lon.min"
        :max="GEO_BOUNDS.lon.max"
        :readonly="readOnly"
        @update:model-value="handleInput"
      />
    </div>

    <InputField
      v-if="showAltitude"
      v-model="alt"
      :name="`${item.key}_alt`"
      :label="t('labels.geo.field.altitude')"
      type="number"
      step="any"
      :readonly="readOnly"
      @update:model-value="handleInput"
    />
  </div>
</template>
