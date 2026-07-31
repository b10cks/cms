import { afterEach, describe, expect, it } from 'vitest'

import { useAssetRequirements } from '~/composables/useAssetRequirements'
import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const SPACE = 'space-1'

const field = (key: string, label: string, required = false) =>
  ({ key, label, required }) as SpaceAssetField

/** Folders form a lineage: root > child > grandchild. */
const folder = (id: string, parent_id: string | null, settings: unknown = undefined) =>
  ({ id, parent_id, settings }) as unknown as AssetFolderResource

const asset = (fields: Record<string, Record<string, unknown>> = {}, folder_id: string | null = null) =>
  ({ id: 'asset-1', folder_id, data: { fields } }) as unknown as Parameters<
    ReturnType<typeof useAssetRequirements>['isCompliant']
  >[0]

// Explicit, not ReturnType<typeof setup>: that would be circular, and TS would
// silently widen the composable's whole surface to `any`.
let harness: Harness<ReturnType<typeof useAssetRequirements>> | undefined

const setup = ({
  assetFields = [field('alt', 'Alt text', true)],
  languages = [] as Array<{ code: string; name: string }>,
  folders = [] as AssetFolderResource[],
} = {}) => {
  harness = withSetup(() => useAssetRequirements(SPACE), {
    seed: [
      [queryKeys.spaces.detail(SPACE), { id: SPACE, settings: { asset_fields: assetFields, languages } }],
      [queryKeys.assetFolders(SPACE).list({}), folders],
    ],
  })

  return harness.result
}

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('languageTabs', () => {
  it('always leads with the default-language tab', () => {
    expect(setup().languageTabs.value).toEqual([{ code: '_default', name: 'Default' }])
  })

  it('appends the space languages after it', () => {
    const codes = setup({ languages: [{ code: 'de', name: 'German' }] }).languageTabs.value.map(
      (tab) => tab.code
    )

    expect(codes).toEqual(['_default', 'de'])
  })
})

describe('getEffectiveFields', () => {
  it('returns the space fields at the root', () => {
    expect(setup().getEffectiveFields()).toEqual([{ key: 'alt', label: 'Alt text', required: true }])
  })

  it('normalizes a field, coercing a missing required flag to false', () => {
    const fields = setup({
      assetFields: [{ key: 'credit', label: 'Credit' } as SpaceAssetField],
    }).getEffectiveFields()

    expect(fields).toEqual([{ key: 'credit', label: 'Credit', required: false }])
  })

  it('adds a folder additional_fields entry', () => {
    const fields = setup({
      folders: [folder('f1', null, { additional_fields: [field('credit', 'Credit')] })],
    }).getEffectiveFields({ folderId: 'f1' })

    expect(fields.map((entry) => entry.key)).toEqual(['alt', 'credit'])
  })

  it('inherits additional fields down the folder lineage', () => {
    const fields = setup({
      folders: [
        folder('root', null, { additional_fields: [field('credit', 'Credit')] }),
        folder('child', 'root'),
      ],
    }).getEffectiveFields({ folderId: 'child' })

    expect(fields.map((entry) => entry.key)).toEqual(['alt', 'credit'])
  })

  it('lets a folder override drop an inherited field', () => {
    const fields = setup({
      folders: [folder('f1', null, { field_overrides: [{ key: 'alt', enabled: false }] })],
    }).getEffectiveFields({ folderId: 'f1' })

    expect(fields).toEqual([])
  })

  it('lets a folder override flip a field to required', () => {
    const fields = setup({
      assetFields: [field('credit', 'Credit')],
      folders: [folder('f1', null, { field_overrides: [{ key: 'credit', required: true }] })],
    }).getEffectiveFields({ folderId: 'f1' })

    expect(fields[0].required).toBe(true)
  })

  it('ignores an override for a field that does not exist', () => {
    const fields = setup({
      folders: [folder('f1', null, { field_overrides: [{ key: 'ghost', required: true }] })],
    }).getEffectiveFields({ folderId: 'f1' })

    expect(fields.map((entry) => entry.key)).toEqual(['alt'])
  })

  it('lets the deeper folder win when both levels override the same field', () => {
    const fields = setup({
      folders: [
        folder('root', null, { field_overrides: [{ key: 'alt', required: false }] }),
        folder('child', 'root', { field_overrides: [{ key: 'alt', required: true }] }),
      ],
    }).getEffectiveFields({ folderId: 'child' })

    expect(fields[0].required).toBe(true)
  })

  it('keeps an override that only sets one of enabled/required', () => {
    const fields = setup({
      folders: [folder('f1', null, { field_overrides: [{ key: 'alt', enabled: true }] })],
    }).getEffectiveFields({ folderId: 'f1' })

    // required is untouched, so the space value survives.
    expect(fields[0].required).toBe(true)
  })

  it('applies unsaved settings on top of the parent lineage', () => {
    const fields = setup({
      folders: [folder('parent', null, { additional_fields: [field('credit', 'Credit')] })],
    }).getEffectiveFields({
      parentFolderId: 'parent',
      settings: { field_overrides: [{ key: 'alt', enabled: false }] },
    })

    // alt disabled by the draft settings, credit inherited from the parent.
    expect(fields.map((entry) => entry.key)).toEqual(['credit'])
  })

  it('ignores an unknown folder id', () => {
    expect(setup().getEffectiveFields({ folderId: 'ghost' }).map((entry) => entry.key)).toEqual([
      'alt',
    ])
  })
})

describe('getFieldStates', () => {
  it('marks space fields as enabled and not inherited', () => {
    expect(setup().getFieldStates()).toEqual([
      { key: 'alt', label: 'Alt text', required: true, enabled: true, custom: false, inherited: false, source: 'space' },
    ])
  })

  it('marks an inherited folder field as custom and inherited', () => {
    const states = setup({
      folders: [
        folder('root', null, { additional_fields: [field('credit', 'Credit')] }),
        folder('child', 'root'),
      ],
    }).getFieldStates({ folderId: 'child' })

    expect(states.find((state) => state.key === 'credit')).toMatchObject({
      custom: true,
      inherited: true,
      source: 'folder',
    })
  })

  it('keeps a disabled field in the state list, so the UI can show the toggle', () => {
    const states = setup({
      folders: [folder('f1', null, { field_overrides: [{ key: 'alt', enabled: false }] })],
    }).getFieldStates({ folderId: 'f1' })

    expect(states).toHaveLength(1)
    expect(states[0].enabled).toBe(false)
  })

  it("marks a field the folder's own draft settings touch as not inherited", () => {
    const states = setup().getFieldStates({
      settings: { field_overrides: [{ key: 'alt', required: false }] },
    })

    expect(states[0].inherited).toBe(false)
  })
})

describe('getEffectiveFieldsForTarget', () => {
  it('prefers the fields the API resolved for the asset', () => {
    const fields = setup().getEffectiveFieldsForTarget({
      effective_asset_fields: [field('credit', 'Credit', true)],
      folder_id: null,
    })

    expect(fields).toEqual([{ key: 'credit', label: 'Credit', required: true }])
  })

  it('falls back to the asset folder when the API resolved nothing', () => {
    const fields = setup({
      folders: [folder('f1', null, { additional_fields: [field('credit', 'Credit')] })],
    }).getEffectiveFieldsForTarget({ folder_id: 'f1', effective_asset_fields: [] })

    expect(fields.map((entry) => entry.key)).toEqual(['alt', 'credit'])
  })

  it('lets an explicit folder id win over the asset folder', () => {
    const fields = setup({
      folders: [folder('f1', null, { additional_fields: [field('credit', 'Credit')] })],
    }).getEffectiveFieldsForTarget({ folder_id: null }, 'f1')

    expect(fields.map((entry) => entry.key)).toEqual(['alt', 'credit'])
  })

  it('tolerates no target at all', () => {
    expect(setup().getEffectiveFieldsForTarget(null).map((entry) => entry.key)).toEqual(['alt'])
  })
})

describe('getFieldValue', () => {
  it('reads a stored value', () => {
    expect(setup().getFieldValue(asset({ _default: { alt: 'A cat' } }), 'alt', '_default')).toBe(
      'A cat'
    )
  })

  it('trims the stored value', () => {
    expect(setup().getFieldValue(asset({ _default: { alt: '  A cat  ' } }), 'alt', '_default')).toBe(
      'A cat'
    )
  })

  it('joins an array value', () => {
    expect(
      setup().getFieldValue(asset({ _default: { alt: ['a', 'b'] } }), 'alt', '_default')
    ).toBe('a b')
  })

  it('stringifies a non-string scalar', () => {
    expect(setup().getFieldValue(asset({ _default: { alt: 42 } }), 'alt', '_default')).toBe('42')
  })

  it.each([
    ['a missing field', {}],
    ['a null value', { _default: { alt: null } }],
    ['a whitespace-only value', { _default: { alt: '   ' } }],
  ])('returns an empty string for %s', (_label, fields) => {
    expect(setup().getFieldValue(asset(fields), 'alt', '_default')).toBe('')
  })

  it('returns an empty string when the asset has no data at all', () => {
    expect(
      setup().getFieldValue({ id: 'a' } as unknown as Parameters<
        ReturnType<typeof useAssetRequirements>['getFieldValue']
      >[0], 'alt', '_default')
    ).toBe('')
  })

  it('does not mutate the asset it reads — query-cache values are readonly', () => {
    const target = asset()

    setup().getFieldValue(target, 'alt', '_default')

    expect(target.data).toEqual({ fields: {} })
  })

  it('keeps languages separate', () => {
    const requirements = setup({ languages: [{ code: 'de', name: 'German' }] })
    const target = asset({ _default: { alt: 'A cat' }, de: { alt: 'Eine Katze' } })

    expect(requirements.getFieldValue(target, 'alt', 'de')).toBe('Eine Katze')
  })
})

describe('ensureAssetFieldData and setFieldValue', () => {
  it('creates the fields container and a bucket per language tab', () => {
    const target = { id: 'a' } as unknown as Parameters<
      ReturnType<typeof useAssetRequirements>['ensureAssetFieldData']
    >[0]

    setup({ languages: [{ code: 'de', name: 'German' }] }).ensureAssetFieldData(target)

    expect(target.data).toEqual({ fields: { _default: {}, de: {} } })
  })

  it('replaces non-object data rather than throwing', () => {
    const target = { id: 'a', data: 'nope' } as unknown as Parameters<
      ReturnType<typeof useAssetRequirements>['ensureAssetFieldData']
    >[0]

    setup().ensureAssetFieldData(target)

    expect(target.data).toEqual({ fields: { _default: {} } })
  })

  it('keeps values already stored', () => {
    const target = asset({ _default: { alt: 'A cat' } })

    setup().ensureAssetFieldData(target)

    expect((target.data as { fields: Record<string, Record<string, unknown>> }).fields._default.alt).toBe(
      'A cat'
    )
  })

  it('writes a value into the right language bucket', () => {
    const target = { id: 'a' } as unknown as Parameters<
      ReturnType<typeof useAssetRequirements>['setFieldValue']
    >[0]

    setup({ languages: [{ code: 'de', name: 'German' }] }).setFieldValue(target, 'alt', 'de', 'Eine Katze')

    expect(target.data).toEqual({ fields: { _default: {}, de: { alt: 'Eine Katze' } } })
  })
})

describe('getVisibleFields and getVisibleLanguages', () => {
  it('shows every effective field when nothing is selected', () => {
    const requirements = setup({ assetFields: [field('alt', 'Alt'), field('credit', 'Credit')] })

    expect(requirements.getVisibleFields().map((entry) => entry.key)).toEqual(['alt', 'credit'])
    expect(requirements.getVisibleFields([]).map((entry) => entry.key)).toEqual(['alt', 'credit'])
    expect(requirements.getVisibleFields(null).map((entry) => entry.key)).toEqual(['alt', 'credit'])
  })

  it('filters to the selected field keys', () => {
    const requirements = setup({ assetFields: [field('alt', 'Alt'), field('credit', 'Credit')] })

    expect(requirements.getVisibleFields(['credit']).map((entry) => entry.key)).toEqual(['credit'])
  })

  it('ignores a selected key that is not an effective field', () => {
    expect(setup().getVisibleFields(['ghost'])).toEqual([])
  })

  it('shows every language tab when nothing is selected', () => {
    const requirements = setup({ languages: [{ code: 'de', name: 'German' }] })

    expect(requirements.getVisibleLanguages().map((entry) => entry.code)).toEqual(['_default', 'de'])
  })

  it('filters to the selected language codes', () => {
    const requirements = setup({ languages: [{ code: 'de', name: 'German' }] })

    expect(requirements.getVisibleLanguages(['de']).map((entry) => entry.code)).toEqual(['de'])
  })
})

describe('isFieldRequiredForLanguage', () => {
  it('is required only in the default language', () => {
    const requirements = setup()
    const required = field('alt', 'Alt', true)

    expect(requirements.isFieldRequiredForLanguage(required, '_default')).toBe(true)
    expect(requirements.isFieldRequiredForLanguage(required, 'de')).toBe(false)
  })

  it('is never required for an optional field', () => {
    expect(setup().isFieldRequiredForLanguage(field('alt', 'Alt'), '_default')).toBe(false)
  })
})

describe('compliance', () => {
  it('flags a required field left empty', () => {
    expect(setup().getMissingRequiredFields(asset())).toEqual([
      {
        fieldKey: 'alt',
        fieldLabel: 'Alt text',
        languageCode: '_default',
        languageLabel: 'Default',
      },
    ])
  })

  it('passes once the required field is filled', () => {
    expect(setup().getMissingRequiredFields(asset({ _default: { alt: 'A cat' } }))).toEqual([])
  })

  it('does not require translations', () => {
    const requirements = setup({ languages: [{ code: 'de', name: 'German' }] })

    expect(requirements.getMissingRequiredFields(asset({ _default: { alt: 'A cat' } }))).toEqual([])
  })

  it('reports nothing when no field is required', () => {
    expect(setup({ assetFields: [field('credit', 'Credit')] }).getMissingRequiredFields(asset())).toEqual(
      []
    )
  })

  it('reports every missing required field', () => {
    const requirements = setup({
      assetFields: [field('alt', 'Alt', true), field('credit', 'Credit', true)],
    })

    expect(
      requirements.getMissingRequiredFields(asset({ _default: { alt: 'A cat' } })).map((issue) => issue.fieldKey)
    ).toEqual(['credit'])
  })

  it('honours a folder override that made a field required', () => {
    const requirements = setup({
      assetFields: [field('credit', 'Credit')],
      folders: [folder('f1', null, { field_overrides: [{ key: 'credit', required: true }] })],
    })

    expect(requirements.getMissingRequiredFields(asset({}, 'f1'))).toHaveLength(1)
  })

  it('honours a folder override that disabled a required field', () => {
    const requirements = setup({
      folders: [folder('f1', null, { field_overrides: [{ key: 'alt', enabled: false }] })],
    })

    expect(requirements.getMissingRequiredFields(asset({}, 'f1'))).toEqual([])
  })

  it('summarizes the issues as field (language) pairs', () => {
    const requirements = setup({
      assetFields: [field('alt', 'Alt text', true), field('credit', 'Credit', true)],
    })

    expect(requirements.getRequirementSummary(asset())).toBe('Alt text (Default), Credit (Default)')
  })

  it('summarizes a compliant asset as an empty string', () => {
    expect(setup().getRequirementSummary(asset({ _default: { alt: 'A cat' } }))).toBe('')
  })

  it('reports compliance as a boolean', () => {
    const requirements = setup()

    expect(requirements.isCompliant(asset())).toBe(false)
    expect(requirements.isCompliant(asset({ _default: { alt: 'A cat' } }))).toBe(true)
  })
})
