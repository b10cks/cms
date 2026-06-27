/**
 * Tolerant parser for JSON produced by language models. Mirrors the backend
 * App\Services\Ai\Support\JsonExtractor: strips ```fences and, failing a clean
 * parse, extracts the first balanced object/array embedded in surrounding text.
 * Returns null when nothing parseable is found.
 */
export function stripAiCodeFences(text: string): string {
  return text
    .replace(/^\s*```(?:json|javascript|js)?\s*\n?/i, '')
    .replace(/\n?```\s*$/i, '')
    .trim()
}

export function parseAiJson<T = unknown>(raw: string | null | undefined): T | null {
  if (!raw || !raw.trim()) {
    return null
  }

  const text = stripAiCodeFences(raw.trim())

  try {
    return JSON.parse(text) as T
  } catch {
    // fall through to balanced extraction
  }

  const balanced = extractBalancedJson(text)
  if (balanced) {
    try {
      return JSON.parse(balanced) as T
    } catch {
      // give up
    }
  }

  return null
}

function extractBalancedJson(text: string): string | null {
  let start = -1
  let open = '{'
  let close = '}'

  for (let i = 0; i < text.length; i++) {
    if (text[i] === '{') {
      start = i
      open = '{'
      close = '}'
      break
    }
    if (text[i] === '[') {
      start = i
      open = '['
      close = ']'
      break
    }
  }

  if (start === -1) {
    return null
  }

  let depth = 0
  let inString = false
  let escaped = false

  for (let i = start; i < text.length; i++) {
    const char = text[i]

    if (inString) {
      if (escaped) {
        escaped = false
      } else if (char === '\\') {
        escaped = true
      } else if (char === '"') {
        inString = false
      }
      continue
    }

    if (char === '"') {
      inString = true
      continue
    }

    if (char === open) {
      depth++
    } else if (char === close) {
      depth--
      if (depth === 0) {
        return text.slice(start, i + 1)
      }
    }
  }

  return null
}
