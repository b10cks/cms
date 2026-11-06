<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { FormField } from '~/components/ui/form';
import { InputGroup, InputGroupAddon, InputGroupInput } from '~/components/ui/input-group';
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

/** The coordinate inputs under the map, altitude only when the schema asks for it. */
const coordinates = computed(() => [
  { model: lat, key: 'lat', label: t('labels.geo.field.latitude'), unit: '°', bounds: GEO_BOUNDS.lat },
  { model: lon, key: 'lon', label: t('labels.geo.field.longitude'), unit: '°', bounds: GEO_BOUNDS.lon },
  ...(showAltitude.value
    ? [{ model: alt, key: 'alt', label: t('labels.geo.field.altitude'), unit: 'm', bounds: null }]
    : []),
])

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
        html: '<span class="block size-3.5 rounded-full bg-accent-fill ring-2 ring-white shadow-md"></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
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
  <FormField
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || undefined"
    :required="item.required"
  >
    <template #default="{ id }">
      <div
        :class="[
          'grid gap-2',
          showMap ? 'overflow-hidden rounded-lg border border-input-border bg-input p-1 shadow-sm' : '',
        ]"
      >
        <div
          v-if="showMap"
          ref="mapEl"
          class="geo-map isolate h-64 w-full overflow-hidden rounded-md bg-surface"
        />
        <div
          :class="[
            'grid gap-2',
            coordinates.length === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2',
          ]"
        >
          <InputGroup
            v-for="(coordinate, index) in coordinates"
            :key="coordinate.key"
            :class="showMap ? 'shadow-none' : ''"
          >
            <InputGroupAddon class="text-input-muted">
              {{ coordinate.label }}
            </InputGroupAddon>
            <InputGroupInput
              :id="index === 0 ? id : undefined"
              :model-value="coordinate.model.value"
              :name="`${item.key}_${coordinate.key}`"
              :aria-label="`${item.name || item.key}, ${coordinate.label}`"
              type="number"
              inputmode="decimal"
              step="any"
              :min="coordinate.bounds?.min"
              :max="coordinate.bounds?.max"
              :readonly="readOnly"
              class="text-right tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
              @update:model-value="
                (value: string | number) => {
                  coordinate.model.value = String(value)
                  handleInput()
                }
              "
            />
            <InputGroupAddon
              align="inline-end"
              class="text-input-muted"
            >
              {{ coordinate.unit }}
            </InputGroupAddon>
          </InputGroup>
        </div>
      </div>
    </template>
  </FormField>
</template>

<style>
/* Leaflet's stylesheet loads unlayered and late, so these win by specificity. */
.geo-map.leaflet-container {
  background: var(--surface);
  font: inherit;
}

.dark .geo-map.leaflet-container .leaflet-tile-pane {
  filter: invert(1) hue-rotate(180deg) brightness(0.95) contrast(0.85) saturate(0.4);
}

.geo-map.leaflet-container .leaflet-bar {
  border: 1px solid var(--popover-border);
  border-radius: 0.5rem;
  box-shadow: var(--shadow-soft-sm);
  overflow: hidden;
}

.geo-map.leaflet-container .leaflet-bar a {
  background: var(--popover);
  border-bottom-color: var(--popover-border);
  color: var(--primary);
  width: 28px;
  height: 28px;
  line-height: 28px;
}

.geo-map.leaflet-container .leaflet-bar a:hover {
  background: color-mix(in oklab, var(--primary) 6%, var(--popover));
}

.geo-map.leaflet-container .leaflet-control-attribution {
  background: color-mix(in oklab, var(--card) 80%, transparent);
  color: var(--input-muted);
  border-top-left-radius: 0.375rem;
}

.geo-map.leaflet-container .leaflet-control-attribution a {
  color: inherit;
}
</style>
