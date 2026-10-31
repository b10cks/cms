import { describe, expect, it } from 'vitest'

import { modelIcon, modelVendor, parseFullModelId } from '~/lib/modelIcons'

describe('modelIcon', () => {
  it('prefers the family logo over the provider', () => {
    expect(modelIcon('openrouter', 'anthropic/claude-sonnet-4.5')).toBe('model:claude')
    expect(modelIcon('openrouter', 'google/gemini-2.5-pro')).toBe('model:gemini')
  })

  it('falls back to the provider logo', () => {
    expect(modelIcon('openrouter', 'mistralai/mistral-medium-3.1')).toBe('model:mistral')
    expect(modelIcon('openai', 'gpt-5')).toBe('model:openai')
  })

  it('reads Bedrock ids, whose vendor ends at a dot', () => {
    expect(modelIcon('bedrock', 'anthropic.claude-3-5-sonnet-20241022-v2:0')).toBe('model:claude')
    expect(modelIcon('bedrock', 'unknown.model-v1')).toBe('model:bedrock')
  })

  it('uses the colour variant only where one exists', () => {
    expect(modelIcon('openrouter', 'anthropic/claude-opus-4.1', 'color')).toBe('model:claude-color')
    expect(modelIcon('openrouter', 'openai/gpt-5', 'color')).toBe('model:openai')
  })

  it('falls back to the AI bot for unknown vendors', () => {
    expect(modelIcon('openrouter', 'someone/new-model')).toBe('ai:bot')
  })
})

describe('modelVendor', () => {
  it('groups prefixes under one vendor and labels unknown ones', () => {
    expect(modelVendor('openrouter', 'meta-llama/llama-3.3-70b-instruct')).toEqual({ key: 'meta', label: 'Meta' })
    expect(modelVendor('openrouter', 'some-lab/model')).toEqual({ key: 'some-lab', label: 'Some Lab' })
  })
})

describe('parseFullModelId', () => {
  it('splits on the first colon only', () => {
    expect(parseFullModelId('openrouter:meta-llama/llama-3.3-70b-instruct:free')).toEqual({
      driver: 'openrouter',
      model: 'meta-llama/llama-3.3-70b-instruct:free',
    })
  })
})
