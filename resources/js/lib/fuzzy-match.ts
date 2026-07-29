export interface FuzzyMatch {
  score: number
  indices: number[]
}

/**
 * A search target with its normalization and word-boundary positions computed
 * once up front, so repeated queries against the same text (every keystroke)
 * skip the expensive per-character work.
 */
export interface FuzzyTarget {
  raw: string
  normalized: string
  boundaries: boolean[]
}

const normalize = (value: string) =>
  value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()

const isWordBoundary = (raw: string, index: number) => {
  if (index === 0) {
    return true
  }

  const prev = raw[index - 1]
  const current = raw[index]

  if (!/[\p{L}\p{N}]/u.test(prev)) {
    return true
  }

  // camelCase / PascalCase boundary
  return /\p{Ll}/u.test(prev) && /\p{Lu}/u.test(current)
}

export function prepareFuzzyTarget(raw: string): FuzzyTarget {
  const boundaries: boolean[] = Array.from({ length: raw.length })
  for (let index = 0; index < raw.length; index++) {
    boundaries[index] = isWordBoundary(raw, index)
  }

  return { raw, normalized: normalize(raw), boundaries }
}

export const prepareFuzzyQuery = (query: string) => normalize(query.trim())

const matchFrom = (query: string, target: FuzzyTarget, start: number): FuzzyMatch | null => {
  const text = target.normalized
  const indices: number[] = []
  let score = 0
  let queryIndex = 0
  let previousIndex = -1

  for (let textIndex = start; textIndex < text.length && queryIndex < query.length; textIndex++) {
    if (text[textIndex] !== query[queryIndex]) {
      continue
    }

    let charScore = 1

    if (previousIndex === textIndex - 1) {
      charScore += 8
    }

    if (target.boundaries[textIndex]) {
      charScore += 10
    } else if (previousIndex >= 0) {
      charScore -= Math.min(textIndex - previousIndex - 1, 5)
    }

    score += charScore
    indices.push(textIndex)
    previousIndex = textIndex
    queryIndex++
  }

  if (queryIndex < query.length) {
    return null
  }

  // Prefer matches that begin earlier in the text.
  return { score: score - Math.floor(start / 2), indices }
}

/**
 * Case- and diacritic-insensitive subsequence match. Rewards consecutive runs
 * and word/camelCase starts, penalizes gaps. Returns the best-scoring match
 * across all possible anchor positions, or null when the query is not a
 * subsequence of the text.
 *
 * Pass a pre-normalized query (prepareFuzzyQuery) and prepared targets
 * (prepareFuzzyTarget) when matching many texts per keystroke; plain strings
 * are prepared on the fly.
 */
export function fuzzyMatch(query: string, text: string | FuzzyTarget): FuzzyMatch | null {
  const target = typeof text === 'string' ? prepareFuzzyTarget(text) : text
  const normalizedQuery = typeof text === 'string' ? prepareFuzzyQuery(query) : query
  const normalizedText = target.normalized

  if (!normalizedQuery || normalizedText.length < normalizedQuery.length) {
    return null
  }

  let best: FuzzyMatch | null = null
  const anchor = normalizedQuery[0]

  for (let start = 0; start <= normalizedText.length - normalizedQuery.length; start++) {
    if (normalizedText[start] !== anchor) {
      continue
    }

    const match = matchFrom(normalizedQuery, target, start)
    if (match && (!best || match.score > best.score)) {
      best = match
    }
  }

  return best
}
