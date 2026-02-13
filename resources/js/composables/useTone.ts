import { useStorage } from '@vueuse/core'

import { tones, type Tone } from '~/components/ui/emoji/utils'

export const useTone = () => {
  const tone = useStorage<Tone>('tone', 'neutral')

  return {
    tone,
    tones,
  }
}
