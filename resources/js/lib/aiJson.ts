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

/**
 * Iterates over the top-level balanced spans delimited by `open`/`close` in
 * `text`, skipping delimiters that appear inside JSON string literals (respecting
 * escapes). Yields the inclusive `[start, end]` index of each complete span;
 * unterminated trailing spans are not yielded. Shared by the whole-value extractor
 * below and the streaming tree-operation parser in `useAiContentTree`.
 */
export function* balancedSpans(
  text: string,
  open: string,
  close: string
): Generator<{ start: number; end: number }> {
  let depth = 0
  let start = -1
  let inString = false
  let escaped = false

  for (let i = 0; i < text.length; i++) {
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
      if (depth === 0) start = i
      depth++
    } else if (char === close && depth > 0) {
      depth--
      if (depth === 0 && start !== -1) {
        yield { start, end: i }
        start = -1
      }
    }
  }
}

function extractBalancedJson(text: string): string | null {
  let open = '{'
  let close = '}'
  let firstIndex = -1

  for (let i = 0; i < text.length; i++) {
    if (text[i] === '{' || text[i] === '[') {
      firstIndex = i
      open = text[i]
      close = text[i] === '{' ? '}' : ']'
      break
    }
  }

  if (firstIndex === -1) {
    return null
  }

  for (const { start, end } of balancedSpans(text, open, close)) {
    return text.slice(start, end + 1)
  }

  return null
}
