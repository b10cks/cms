import { describe, expect, it, vi } from 'vitest'

import { AI_ERROR_REASONS, aiErrorMessage, isPlanError } from '~/lib/aiErrors'
import { i18n } from '~/plugins/i18n'

const t = i18n.global.t.bind(i18n.global) as (key: string) => string

describe('aiErrorMessage', () => {
  it.each([
    ['not_configured', 'This space has no AI configuration yet.'],
    ['provider_unavailable', 'The AI provider is currently unavailable.'],
    ['not_provisioned', 'Your AI access is still being set up.'],
    ['plan_excluded', "Your current plan doesn't include AI features."],
    ['no_result', "The AI service didn't return a usable result."],
    ['csrf', 'Your session has expired.'],
    ['generic', 'Something went wrong with the AI request.'],
  ])('translates the known reason %s', (reason, expected) => {
    expect(aiErrorMessage(t, reason)).toContain(expected)
  })

  it('prefers the known reason over a supplied fallback', () => {
    expect(aiErrorMessage(t, 'csrf', 'raw backend text')).toBe(
      'Your session has expired. Please refresh the page and try again.'
    )
  })

  it('uses the fallback for an unknown reason', () => {
    expect(aiErrorMessage(t, 'quota_exceeded', 'raw backend text')).toBe('raw backend text')
  })

  it.each([[undefined], [null], ['']])(
    'uses the fallback when the reason is %s',
    (reason: string | null | undefined) => {
      expect(aiErrorMessage(t, reason, 'raw backend text')).toBe('raw backend text')
    }
  )

  it('falls back to the generic message with no reason and no fallback', () => {
    expect(aiErrorMessage(t)).toBe('Something went wrong with the AI request. Please try again.')
  })

  it.each([[undefined], [null], ['']])(
    'falls back to the generic message for a %s fallback',
    (fallback: string | null | undefined) => {
      expect(aiErrorMessage(t, 'unknown_reason', fallback)).toBe(
        'Something went wrong with the AI request. Please try again.'
      )
    }
  )

  it('only looks up reasons under the composables.ai.errors namespace', () => {
    const translate = vi.fn(() => 'translated')

    aiErrorMessage(translate, 'no_result')

    expect(translate).toHaveBeenCalledWith('composables.ai.errors.no_result')
  })

  // The backend selects these by reason too, so every key in the catalogue has
  // to be selectable — a supplied fallback must not win over the translation.
  it('accepts every reason that exists in the message catalogue', () => {
    expect(aiErrorMessage(t, 'csrfUnavailable', 'fallback')).not.toBe('fallback')
    expect(aiErrorMessage(t, 'noSpace', 'fallback')).not.toBe('fallback')
    expect(aiErrorMessage(t, 'noSpaceOrDataSource', 'fallback')).not.toBe('fallback')
  })

  // AI_ERROR_REASONS is the single source for both the union and the lookup
  // set, so a reason can never be added without a message behind it.
  it('has a real translation behind every declared reason', () => {
    for (const reason of AI_ERROR_REASONS) {
      const message = aiErrorMessage(t, reason)
      expect(message).not.toContain('composables.ai.errors')
      expect(message.length).toBeGreaterThan(0)
    }
  })

  it('does not translate a reason with the wrong casing', () => {
    expect(aiErrorMessage(t, 'CSRF', 'fallback')).toBe('fallback')
  })

  it('never leaks a raw i18n key to the user', () => {
    expect(aiErrorMessage(t, 'plan_excluded')).not.toContain('composables.ai.errors')
  })
})

describe('isPlanError', () => {
  it('is true only for plan_excluded', () => {
    expect(isPlanError('plan_excluded')).toBe(true)
  })

  it.each([['csrf'], ['generic'], [''], [undefined], [null]])(
    'is false for %s',
    (reason: string | null | undefined) => {
      expect(isPlanError(reason)).toBe(false)
    }
  )
})
