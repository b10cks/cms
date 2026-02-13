export type Emoji = {
  n: string[]
  u: string
  v?: string[]
}

export type EmojiGroups = {
  [key: string]: Emoji[]
}

import emojiGroups from './emojis.json'
export { emojiGroups }

export const tones = ['neutral', '1f3fb', '1f3fc', '1f3fd', '1f3fe', '1f3ff']
export type Tone = (typeof tones)[number]

export function unicodeToEmoji(unicode: string): string {
  return unicode
    .split('-')
    .map((hex) => parseInt(hex, 16))
    .map((hex) => String.fromCodePoint(hex))
    .join('')
}

export function emojiToChar(emoji: Emoji, tone: Tone = 'neutral'): string {
  const u = emoji.u
  if (emoji?.v && tone != 'neutral') {
    const index = emoji.v.indexOf(`${u}-${tone}`)
    if (index >= 0) {
      return unicodeToEmoji(emoji.v[index])
    }
  }
  return unicodeToEmoji(emoji.u)
}

export function emojiToValue(emoji: Emoji): string {
  const v = emoji.n[emoji.n.length - 1]
  return `:${v}:`
}

export function valueToEmoji(value: string): Emoji | undefined {
  const [_, name] = value.split(':')
  if (!name) return undefined

  for (const group of Object.values(emojiGroups)) {
    for (const emoji of group) {
      if (emoji.n.includes(name)) return emoji
    }
  }

  return undefined
}

export function injectFavorites(emojis: EmojiGroups, favorites: Emoji[]): EmojiGroups {
  return { favorites, ...emojis }
}

export function filterEmojis(emojis: EmojiGroups, keyword: string): EmojiGroups {
  const result: EmojiGroups = {}
  for (const [group, list] of Object.entries(emojis)) {
    const filteredEmojis = list.filter((emoji) => emoji.n.some((name) => name.includes(keyword)))
    if (filteredEmojis.length > 0) result[group] = filteredEmojis
  }
  return result
}
