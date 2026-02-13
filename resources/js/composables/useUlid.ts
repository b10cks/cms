export function useUlid() {
  const lastTime = ref<string | null>(null)
  const lastRandom = ref<number[]>([0, 0, 0, 0])

  const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
  const ENCODING_LEN = ENCODING.length

  return (time?: Date): string => {
    const now = time ? time.getTime() : Date.now()
    const nowStr = now.toString()

    if (nowStr === lastTime.value) {
      let i
      for (i = 3; i >= 0; i--) {
        if (lastRandom.value[i] === 0xfffff) {
          lastRandom.value[i] = 0
        } else {
          lastRandom.value[i]++
          break
        }
      }

      if (i < 0) {
        lastTime.value = (now + 1).toString()
        lastRandom.value = [0, 0, 0, 0].map(() => Math.floor(Math.random() * 0x100000))
      }
    } else {
      const r: number[] = [0, 0, 0, 0]
      for (let i = 0; i < 4; i++) {
        r[i] = Math.floor(Math.random() * 0x100000)
      }

      lastRandom.value = r
      lastTime.value = nowStr
    }

    const timeInt = parseInt(lastTime.value as string, 10)
    let timeStr = ''
    let t = timeInt

    while (timeStr.length < 10) {
      timeStr = ENCODING[t % ENCODING_LEN] + timeStr
      t = Math.floor(t / ENCODING_LEN)
    }
    timeStr = timeStr.padStart(10, '0')

    const randomStr = lastRandom.value
      .map((r) => {
        let s = ''
        let val = r

        while (s.length < 4) {
          s = ENCODING[val % ENCODING_LEN] + s
          val = Math.floor(val / ENCODING_LEN)
        }

        return s
      })
      .join('')

    return (timeStr + randomStr).toLowerCase()
  }
}
