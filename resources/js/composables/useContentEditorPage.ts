import { useRouteQuery } from '@vueuse/router'
import type { Ref } from 'vue'

import { getContentDefaultLanguage, resolveContentLanguage } from '~/lib/content-i18n'
import {
  createEditVersionDirtyTracker,
  createSnapshotDirtyTracker,
  type DirtyTracker,
} from '~/lib/contentEditorState'
import { useUnsavedChangesGuard } from '~/lib/unsavedChangesGuard'
import type { ContentResource } from '~/types/contents'

import type { useContentSchemaState } from './useContentSchemaState'

/** Validation state as produced by `useContentSchemaState`. */
export type ContentValidationState = ReturnType<typeof useContentSchemaState>

export type ContentDirtyStrategy = 'edit-version' | 'snapshot'

export interface UseContentEditorPageOptions {
  /** The document being edited. */
  content: Ref<ContentResource | null>
  /** Last state known to be on the server; the dirty baseline. */
  persistedContent: Ref<ContentResource | null>
  /** Content the route resolved to, before any canonical/language redirect. */
  routeContent: Ref<ContentResource | null | undefined>
  /** Canonical entry that owns the language versions. */
  canonicalContent: Ref<ContentResource | null | undefined>
  /** Space default language, if the space has one configured. */
  spaceDefaultLanguage: Ref<string | null | undefined>
  /**
   * `edit-version` counts mutations (cheap for the full block tree),
   * `snapshot` compares against the baseline (undo makes it clean again).
   */
  dirtyStrategy: ContentDirtyStrategy
  /** Run when the editor leaves with unsaved changes the user chose to drop. */
  onDiscardChanges?: () => void
}

export interface UseContentEditorPageReturn {
  /** Language the space (or the content) considers canonical. */
  defaultLanguage: Ref<string>
  /** The `?lang` query, absent while the default language is shown. */
  languageQuery: Ref<string | undefined>
  /** `?lang` narrowed to a language the content actually has. */
  resolvedLanguage: Ref<string>
  isDirty: Ref<boolean>
  markSaved: () => void
  /** Publish the validation state to the editor/header component tree. */
  provideValidationState: (validation: ContentValidationState) => void
}

/**
 * Wiring shared by the two content editor pages (block editor and
 * localization): language resolution off the canonical entry, dirty tracking,
 * the unsaved-changes guards, and the validation contract the editor fields and
 * the header actions inject.
 *
 * Deliberately not owning the content queries or the publish flow: the two
 * pages resolve their document differently (a language version vs. a synthesized
 * translation draft), and publishing lives in `HeaderActions`, which both pages
 * already share.
 */
export function useContentEditorPage(
  options: UseContentEditorPageOptions
): UseContentEditorPageReturn {
  const defaultLanguage = computed(() =>
    getContentDefaultLanguage(
      options.spaceDefaultLanguage.value,
      options.routeContent.value?.language_versions,
      options.routeContent.value?.language_iso
    )
  )
  const languageQuery = useRouteQuery<string | undefined>('lang')
  const resolvedLanguage = computed(() =>
    resolveContentLanguage(
      languageQuery.value,
      defaultLanguage.value,
      options.canonicalContent.value?.language_versions,
      options.routeContent.value?.language_iso
    )
  )

  const dirty: DirtyTracker =
    options.dirtyStrategy === 'edit-version'
      ? createEditVersionDirtyTracker(options.content)
      : createSnapshotDirtyTracker(options.content, options.persistedContent)

  const { isDirty } = dirty

  useUnsavedChangesGuard({
    isDirty,
    onDiscardChanges: options.onDiscardChanges,
    defaultLanguage,
  })

  const provideValidationState = (validation: ContentValidationState) => {
    provide('markFieldDirty', validation.markFieldDirty)
    provide('getFieldError', validation.getFieldError)
    provide('shouldShowFieldError', validation.shouldShowFieldError)
    provide('setValidationErrors', validation.setServerErrors)
    provide('clearValidationErrors', validation.clearServerErrors)
    provide('getClientValidationErrors', validation.getClientErrors)
    provide('getValidationSummary', () => validation.validationSummary.value)
    provide('getVisibleValidationEntries', validation.getVisibleValidationEntries)
    provide('getValidationIssueSignature', validation.getValidationIssueSignature)
    provide('sanitizeContentForSubmit', () => validation.sanitizedContent.value)
    provide('validateContentForSubmit', validation.validateAllForSubmit)
    provide('revealValidationState', validation.revealValidationState)
    provide('submitValidationAttempted', validation.submitAttempted)
    provide('focusFirstValidationError', validation.focusFirstInvalidField)
  }

  return {
    defaultLanguage,
    languageQuery,
    resolvedLanguage,
    isDirty,
    markSaved: dirty.markSaved,
    provideValidationState,
  }
}
