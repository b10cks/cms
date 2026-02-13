import { createOperationsGenerator, defineProvider } from '@nuxt/image/runtime'

interface IlumTransformations {
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

interface IlumModifiers extends IlumTransformations {
  path?: string
}

const operationsGenerator = createOperationsGenerator({
  keyMap: {
    width: 'w',
    height: 'h',
    crop: 'c',
    gravity: 'g',
    quality: 'quality',
    format: 'format',
    x: 'x',
    y: 'y',
    targetWidth: 'tw',
    targetHeight: 'th',
  },
  joinWith: ',',
  formatter: (key: string, value: string | number) => {
    // Handle special cases for gravity coordinates
    if (key === 'g' && typeof value === 'string' && value.includes('_')) {
      return `${key}_${value}`
    }
    return `${key}_${value}`
  },
})

export default defineProvider({
  getImage(
    src: string,
    { modifiers = {}, baseURL = '' }: { modifiers?: IlumModifiers; baseURL?: string } = {}
  ) {
    const { format, quality, ...transformations } = modifiers
    const operations = operationsGenerator(transformations as Record<string, never>)
    let finalPath = src

    if (operations) {
      finalPath += `/${operations}`
    }

    const searchParams = new URLSearchParams()
    if (format) searchParams.set('format', format)
    if (quality) searchParams.set('quality', quality.toString())

    const queryString = searchParams.toString()
    if (queryString) {
      finalPath += `?${queryString}`
    }

    return {
      url: baseURL + finalPath,
    }
  },
})
