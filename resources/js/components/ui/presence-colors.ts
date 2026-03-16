import colors from './colors.json'

interface PresenceColorOption {
  value: string | null
  label: string
}

export interface PresenceColor {
  value: string
  label: string
}

export const presenceColors = (colors as PresenceColorOption[]).filter(
  (color): color is PresenceColor => color.value !== null
)

const FALLBACK_COLOR: PresenceColor = {
  value: '#3B82F6',
  label: 'Blue',
}

const hashValue = (value: string): number => {
  return value.split('').reduce((hash, char) => {
    return (hash * 31 + char.charCodeAt(0)) >>> 0
  }, 0)
}

export const getPresenceColor = (key: string): PresenceColor => {
  if (presenceColors.length === 0) {
    return FALLBACK_COLOR
  }

  return presenceColors[hashValue(key) % presenceColors.length] ?? FALLBACK_COLOR
}
