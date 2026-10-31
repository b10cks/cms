import { addCollection } from '@iconify/vue'

// Model family and provider logos, extracted from @lobehub/icons-static-svg
// 1.101.0 (MIT). Each logo has a mono body (`claude`) and, where Lobe ships one,
// a brand-colour body (`claude-color`). Registered here rather than in main.ts
// so the set only loads with the AI components that use it.
import modelIcons from '~/lib/model-icons.json'

addCollection(modelIcons)

export interface ModelVendor {
  /** Stable key, used for grouping and as the provider logo. */
  key: string
  label: string
}

/** Provider prefix (OpenRouter `vendor/model`, Bedrock `vendor.model`) to vendor. */
const VENDORS: Record<string, ModelVendor> = {
  anthropic: { key: 'anthropic', label: 'Anthropic' },
  openai: { key: 'openai', label: 'OpenAI' },
  google: { key: 'google', label: 'Google' },
  'meta-llama': { key: 'meta', label: 'Meta' },
  meta: { key: 'meta', label: 'Meta' },
  mistralai: { key: 'mistral', label: 'Mistral' },
  mistral: { key: 'mistral', label: 'Mistral' },
  deepseek: { key: 'deepseek', label: 'DeepSeek' },
  qwen: { key: 'qwen', label: 'Qwen' },
  'x-ai': { key: 'xai', label: 'xAI' },
  cohere: { key: 'cohere', label: 'Cohere' },
  perplexity: { key: 'perplexity', label: 'Perplexity' },
  amazon: { key: 'aws', label: 'Amazon' },
  minimax: { key: 'minimax', label: 'MiniMax' },
  moonshotai: { key: 'moonshot', label: 'Moonshot AI' },
  'z-ai': { key: 'zai', label: 'Z.ai' },
  thudm: { key: 'zai', label: 'Z.ai' },
  nvidia: { key: 'nvidia', label: 'NVIDIA' },
  microsoft: { key: 'microsoft', label: 'Microsoft' },
  baidu: { key: 'wenxin', label: 'Baidu' },
  bytedance: { key: 'bytedance', label: 'ByteDance' },
  'bytedance-seed': { key: 'bytedance', label: 'ByteDance' },
  tencent: { key: 'hunyuan', label: 'Tencent' },
  'stepfun-ai': { key: 'stepfun', label: 'StepFun' },
  inception: { key: 'inception', label: 'Inception' },
  liquid: { key: 'liquid', label: 'Liquid' },
  'arcee-ai': { key: 'arcee', label: 'Arcee' },
  ai21: { key: 'ai21', label: 'AI21' },
  inflection: { key: 'inflection', label: 'Inflection' },
  openrouter: { key: 'openrouter', label: 'OpenRouter' },
}

/** Model families with their own logo, matched against the model part of the id. */
const FAMILIES: Array<[RegExp, string]> = [
  [/claude/, 'claude'],
  [/gemini/, 'gemini'],
  [/gemma/, 'gemma'],
  [/grok/, 'grok'],
  [/nova/, 'nova'],
  [/kimi/, 'kimi'],
  [/ernie/, 'wenxin'],
  [/hunyuan/, 'hunyuan'],
]

/** Drivers that talk to a single vendor. */
const DRIVER_VENDORS: Record<string, string> = { openai: 'openai' }

const splitModelId = (driver: string, model: string): [prefix: string | null, name: string] => {
  const separator = driver === 'bedrock' ? '.' : '/'
  const index = model.indexOf(separator)
  if (index === -1) return [DRIVER_VENDORS[driver] ?? null, model]
  // OpenRouter marks its "latest" aliases with a tilde: `~openai/gpt-latest`.
  return [model.slice(0, index).replace(/^~/, ''), model.slice(index + 1)]
}

const titleCase = (value: string) => value.replace(/(^|[-_])(\w)/g, (_, gap: string, letter: string) => (gap ? ' ' : '') + letter.toUpperCase())

/** The company behind a model. Unknown prefixes keep a readable label and no logo. */
export function modelVendor(driver: string, model: string): ModelVendor {
  const [prefix] = splitModelId(driver, model)
  if (!prefix) return { key: driver, label: titleCase(driver) }
  return VENDORS[prefix.toLowerCase()] ?? { key: prefix.toLowerCase(), label: titleCase(prefix) }
}

/**
 * Icon name for a model: its family logo where Lobe has one (Claude, Gemini),
 * else its provider's, else the AI bot. `color` picks the brand-colour variant
 * when one exists; logos without one stay `currentColor`.
 */
export function modelIcon(driver: string, model: string, variant: 'mono' | 'color' = 'mono'): string {
  const [, name] = splitModelId(driver, model)
  const family = FAMILIES.find(([pattern]) => pattern.test(name.toLowerCase()))?.[1]
  const key = family ?? modelVendor(driver, model).key

  if (!(key in modelIcons.icons)) return driver === 'bedrock' ? iconName('bedrock', variant) : 'ai:bot'
  return iconName(key, variant)
}

const iconName = (key: string, variant: 'mono' | 'color') =>
  variant === 'color' && `${key}-color` in modelIcons.icons ? `model:${key}-color` : `model:${key}`

/** `driver:model` back into its parts; model ids may contain `:` themselves (`…:free`). */
export function parseFullModelId(fullId: string): { driver: string; model: string } {
  const index = fullId.indexOf(':')
  return index === -1 ? { driver: fullId, model: '' } : { driver: fullId.slice(0, index), model: fullId.slice(index + 1) }
}

/** Logo of a vendor key from `modelVendor`, for provider lists. */
export function vendorIcon(key: string, variant: 'mono' | 'color' = 'mono'): string {
  return key in modelIcons.icons ? iconName(key, variant) : 'ai:bot'
}

/** A model name without the `Vendor: ` prefix OpenRouter puts on it; the logo already says who. */
export const modelDisplayName = (name: string) => name.replace(/^[^:]{1,40}:\s+/, '')
