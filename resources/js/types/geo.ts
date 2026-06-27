/**
 * Value stored by the `geo` content field.
 *
 * The object keys depend on the field's configured `key_style` (see `resolveGeoKeys`), so a value
 * is a plain map of the resolved key names to numbers. Altitude is `null` when left blank and the
 * key is omitted entirely when altitude handling is disabled on the field.
 */
export type GeoValue = Record<string, number | null>

export interface GeoKeyNames {
  lat: string
  lon: string
  alt: string
}

/**
 * Maps a storage-key preset to the concrete JSON key names used by the stored value.
 * Single source of truth for the frontend; mirrored on the backend in ContentSchemaValidator.
 */
export const GEO_KEY_STYLES: Record<GeoKeyStyle, GeoKeyNames> = {
  latitude_longitude: { lat: 'latitude', lon: 'longitude', alt: 'altitude' },
  lat_lng: { lat: 'lat', lon: 'lng', alt: 'alt' },
  lat_lon: { lat: 'lat', lon: 'lon', alt: 'alt' },
}

export const resolveGeoKeys = (style?: GeoKeyStyle | null): GeoKeyNames =>
  GEO_KEY_STYLES[style ?? 'lat_lng'] ?? GEO_KEY_STYLES.lat_lng

/** Standard geographic ranges enforced for latitude / longitude. */
export const GEO_BOUNDS = {
  lat: { min: -90, max: 90 },
  lon: { min: -180, max: 180 },
} as const

/** Default raster tile URL for the optional Leaflet map picker (overridable via env). */
export const GEO_TILE_URL =
  (import.meta.env?.VITE_MAP_TILE_URL as string | undefined) ??
  'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
