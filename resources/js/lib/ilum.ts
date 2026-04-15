export interface IlumModifiers {
  width?: number
  height?: number
  crop?: 'fill' | 'fit' | 'crop'
  gravity?: 'face' | 'center' | 'auto' | string
  quality?: number
  format?: string
  x?: number
  y?: number
  targetWidth?: number
  targetHeight?: number
}

const keyMap: Record<string, string> = {
  width: 'w',
  height: 'h',
  crop: 'c',
  gravity: 'g',
  x: 'x',
  y: 'y',
  targetWidth: 'tw',
  targetHeight: 'th',
}

export function generateIlumOperations(modifiers: IlumModifiers): string {
  const operations: string[] = []

  for (const [key, value] of Object.entries(modifiers)) {
    if (value === undefined) {
      continue
    }

    const mappedKey = keyMap[key]
    if (mappedKey) {
      operations.push(`${mappedKey}_${value}`)
    }
  }

  return operations.join(',')
}

export function buildIlumUrl(src: string, modifiers: IlumModifiers = {}, baseURL = ''): string {
  const { format, quality, ...transformations } = modifiers
  const normalizedBaseURL = baseURL.endsWith('/') ? baseURL.slice(0, -1) : baseURL
  let finalPath = src.startsWith('/') ? src : `/${src}`
  const operations = generateIlumOperations(transformations)

  if (operations) {
    finalPath += `/${operations}`
  }

  const searchParams = new URLSearchParams()
  if (format) searchParams.set('format', format)
  if (quality !== undefined) searchParams.set('quality', quality.toString())

  const queryString = searchParams.toString()
  if (queryString) {
    finalPath += `?${queryString}`
  }

  return normalizedBaseURL + finalPath
}
