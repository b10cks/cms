import { getCurrentScope, onScopeDispose, shallowRef } from 'vue'

/**
 * Keyboard shortcut registry.
 *
 * Lives in `lib/` so both composables and plain modules can import it without
 * dropping anything out of the auto-import map. The public entry point for
 * components is `useShortcut()` from `composables/useShortcuts.ts`.
 *
 * One capture-phase window listener dispatches to the most recently registered
 * scope; `global` bindings only fire when no active scope claimed the key.
 */

export const IS_MAC =
  typeof navigator !== 'undefined' && /mac|iphone|ipad/i.test(navigator.userAgent)

/** `global` is the implicit fallback scope; everything else shadows it. */
export const GLOBAL_SCOPE = 'global'

export interface ShortcutOptions {
  /** e.g. `mod+s`, `shift+mod+z`, `alt+arrowup`, `?`. `mod` is Cmd on mac, Ctrl elsewhere. */
  keys: string
  /** Component-lifetime scope. Defaults to `global`. */
  scope?: string
  /** Lazily resolved so the help overlay picks up locale changes. */
  description: () => string
  /** `null` documents a shortcut owned by a widget's own key engine. */
  handler: ((event: KeyboardEvent) => void) | null
  /** Fire even while focus sits in an input/textarea/contenteditable. */
  allowInInput?: boolean
  /** Fire even while focus sits inside a dialog / menu / listbox. */
  allowInOverlay?: boolean
  /** Temporarily disable without unregistering (keeps the overlay entry). */
  enabled?: () => boolean
}

export interface ShortcutInfo {
  keys: string
  description: string
  scope: string
  active: boolean
  /** Display tokens, already localised to the platform, e.g. `['⌘', 'S']`. */
  tokens: string[]
}

interface ParsedKeys {
  key: string
  mod: boolean
  alt: boolean
  /** `null` means "don't care" — symbol keys carry shift implicitly. */
  shift: boolean | null
}

interface Binding extends ParsedKeys {
  id: number
  order: number
  options: ShortcutOptions
  scope: string
}

const KEY_ALIASES: Record<string, string> = {
  esc: 'escape',
  up: 'arrowup',
  down: 'arrowdown',
  left: 'arrowleft',
  right: 'arrowright',
  space: ' ',
  spacebar: ' ',
  ret: 'enter',
  return: 'enter',
  del: 'delete',
}

const CODE_TO_CHAR: Record<string, string> = {
  Period: '.',
  Comma: ',',
  Slash: '/',
  Minus: '-',
  Equal: '=',
  BracketLeft: '[',
  BracketRight: ']',
  Backslash: '\\',
  Quote: "'",
  Semicolon: ';',
  Backquote: '`',
}

const isSymbolKey = (key: string) => key.length === 1 && !/[a-z0-9 ]/.test(key)

export function parseKeys(keys: string): ParsedKeys {
  const parts = keys
    .toLowerCase()
    .split('+')
    .map((part) => part.trim())
    .filter(Boolean)

  // A trailing `+` binding ("mod++") collapses to an empty tail; fall back to `+`.
  const raw = parts.pop() ?? '+'
  const key = KEY_ALIASES[raw] ?? raw
  const hasShift = parts.includes('shift')

  return {
    key,
    mod: parts.includes('mod') || parts.includes('ctrl') || parts.includes('cmd'),
    alt: parts.includes('alt') || parts.includes('option'),
    shift: hasShift ? true : isSymbolKey(key) ? null : false,
  }
}

/** Every identity the pressed key can be addressed by (layout-tolerant). */
function eventKeyCandidates(event: KeyboardEvent): string[] {
  const candidates = [event.key.toLowerCase()]
  const { code } = event

  if (/^Key[A-Z]$/.test(code)) {
    candidates.push(code.slice(3).toLowerCase())
  } else if (/^Digit[0-9]$/.test(code)) {
    candidates.push(code.slice(5))
  } else if (code in CODE_TO_CHAR) {
    candidates.push(CODE_TO_CHAR[code])
  }

  return candidates
}

function matches(binding: ParsedKeys, event: KeyboardEvent): boolean {
  const mod = IS_MAC ? event.metaKey : event.ctrlKey

  if (mod !== binding.mod) return false
  if (event.altKey !== binding.alt) return false
  if (binding.shift !== null && event.shiftKey !== binding.shift) return false
  if (IS_MAC && event.ctrlKey && !binding.mod) return false

  return eventKeyCandidates(event).includes(binding.key)
}

const EDITABLE_SELECTOR =
  'input, textarea, select, [contenteditable=""], [contenteditable="true"], [role="combobox"], [role="textbox"]'

const OVERLAY_SELECTOR =
  '[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]'

/**
 * The one predicate for "the user is typing here". Replaces the three
 * hand-rolled variants that used to live in canvas, AssetGrid and ContentTree.
 */
export function isEditableTarget(target: EventTarget | null): boolean {
  return target instanceof Element && !!target.closest(EDITABLE_SELECTOR)
}

/** True while focus sits inside a reka-ui overlay layered above the page. */
export function isInsideOverlay(target: EventTarget | null): boolean {
  if (target instanceof Element && target.closest(OVERLAY_SELECTOR)) {
    return true
  }

  const active = typeof document !== 'undefined' ? document.activeElement : null

  return active instanceof Element && !!active.closest(OVERLAY_SELECTOR)
}

const bindings = shallowRef<Binding[]>([])
let nextId = 0
let nextOrder = 0
let listening = false

/** Highest registration order per scope — later scopes shadow earlier ones. */
function scopeRecency(): Map<string, number> {
  const recency = new Map<string, number>()

  for (const binding of bindings.value) {
    const current = recency.get(binding.scope)
    if (current === undefined || binding.order > current) {
      recency.set(binding.scope, binding.order)
    }
  }

  return recency
}

export function topScope(): string | null {
  let top: string | null = null
  let best = -1

  for (const [scope, order] of scopeRecency()) {
    if (scope !== GLOBAL_SCOPE && order > best) {
      best = order
      top = scope
    }
  }

  return top
}

function isRunnable(binding: Binding, event: KeyboardEvent): boolean {
  const { options } = binding

  if (!options.handler) return false
  if (options.enabled && !options.enabled()) return false
  if (!options.allowInInput && isEditableTarget(event.target)) return false
  if (!options.allowInOverlay && isInsideOverlay(event.target)) return false

  return true
}

function resolve(event: KeyboardEvent): Binding | null {
  const candidates = bindings.value.filter(
    (binding) => matches(binding, event) && isRunnable(binding, event)
  )

  if (candidates.length === 0) return null

  const recency = scopeRecency()
  const scoped = candidates.filter((binding) => binding.scope !== GLOBAL_SCOPE)
  const pool = scoped.length > 0 ? scoped : candidates

  return pool.reduce((best, binding) => {
    const bestRank = recency.get(best.scope) ?? -1
    const rank = recency.get(binding.scope) ?? -1

    if (rank !== bestRank) return rank > bestRank ? binding : best

    return binding.order > best.order ? binding : best
  })
}

function onKeydown(event: KeyboardEvent) {
  if (event.defaultPrevented || event.repeat) return

  const binding = resolve(event)
  if (!binding?.options.handler) return

  event.preventDefault()
  event.stopPropagation()
  binding.options.handler(event)
}

function ensureListener() {
  if (listening || typeof window === 'undefined') return

  window.addEventListener('keydown', onKeydown, { capture: true })
  listening = true
}

function warnOnConflict(binding: Binding) {
  if (!import.meta.env.DEV || !binding.options.handler) return

  const clash = bindings.value.find(
    (other) =>
      other.id !== binding.id &&
      other.scope === binding.scope &&
      other.options.handler &&
      other.key === binding.key &&
      other.mod === binding.mod &&
      other.alt === binding.alt &&
      other.shift === binding.shift
  )

  if (clash) {
    console.warn(
      `[shortcuts] "${binding.options.keys}" is bound twice in scope "${binding.scope}": ` +
        `"${clash.options.description()}" vs "${binding.options.description()}"`
    )
  }
}

/** Registers immediately and returns the unregister function. */
export function registerShortcut(options: ShortcutOptions): () => void {
  const binding: Binding = {
    id: nextId++,
    order: nextOrder++,
    options,
    scope: options.scope ?? GLOBAL_SCOPE,
    ...parseKeys(options.keys),
  }

  bindings.value = [...bindings.value, binding]
  ensureListener()
  warnOnConflict(binding)

  return () => {
    bindings.value = bindings.value.filter((entry) => entry.id !== binding.id)
  }
}

const MAC_TOKENS: Record<string, string> = {
  mod: '⌘',
  cmd: '⌘',
  ctrl: '⌃',
  alt: '⌥',
  option: '⌥',
  shift: '⇧',
}

const PC_TOKENS: Record<string, string> = {
  mod: 'Ctrl',
  cmd: 'Ctrl',
  ctrl: 'Ctrl',
  alt: 'Alt',
  option: 'Alt',
  shift: 'Shift',
}

const NAMED_TOKENS: Record<string, string> = {
  arrowup: '↑',
  arrowdown: '↓',
  arrowleft: '←',
  arrowright: '→',
  enter: 'Enter',
  escape: 'Esc',
  backspace: 'Backspace',
  delete: 'Delete',
  tab: 'Tab',
  ' ': 'Space',
}

/** Turns `mod+shift+p` into the platform's display tokens. */
export function formatKeys(keys: string): string[] {
  const modifiers = IS_MAC ? MAC_TOKENS : PC_TOKENS

  return keys
    .split('+')
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => {
      const lower = part.toLowerCase()
      const alias = KEY_ALIASES[lower] ?? lower

      return modifiers[lower] ?? NAMED_TOKENS[alias] ?? (alias.length === 1 ? alias.toUpperCase() : part)
    })
}

/** Every registered binding, newest scope first — the help overlay's data source. */
export function listShortcuts(): ShortcutInfo[] {
  const top = topScope()

  return bindings.value
    .filter((binding) => binding.options.enabled?.() !== false)
    .map((binding) => ({
      keys: binding.options.keys,
      description: binding.options.description(),
      scope: binding.scope,
      active: binding.scope === GLOBAL_SCOPE || binding.scope === top,
      tokens: formatKeys(binding.options.keys),
    }))
}

/**
 * Registers for the lifetime of the calling component. Used by `useShortcut()`
 * and by `lib/` helpers that must not import a composable.
 */
export function useShortcutBinding(options: ShortcutOptions): void {
  const dispose = registerShortcut(options)

  if (getCurrentScope()) {
    onScopeDispose(dispose)
  }
}
