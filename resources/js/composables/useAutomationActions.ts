import type { MaybeRefOrGetter } from 'vue'

import { api } from '~/api'
import type { AutomationActionsQueryParams } from '~/api/resources/automation-actions'
import { createCrudComposable } from '~/lib/crud-composable'

import { queryKeys } from './useQueryClient'

const useAutomationActionsCrud = createCrudComposable<
  AutomationActionResource,
  ApiCollectionResponse<AutomationActionResource>,
  AutomationActionsQueryParams,
  CreateAutomationActionPayload,
  UpdateAutomationActionPayload
>({
  i18nKey: 'automationActions',
  keys: (spaceId) => queryKeys.automationActions(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).automationActions,
  toastValues: (data) => ({ name: data.name }),
  // An automation embeds its actions, so editing one restages the parent list.
  invalidateAlso: (spaceId, operation) =>
    operation === 'update' ? [queryKeys.automations(spaceId).lists()] : [],
})

export function useAutomationActions(spaceId: MaybeRefOrGetter<string>) {
  const crud = useAutomationActionsCrud(spaceId)

  return {
    useAutomationActionsQuery: crud.useListQuery,
    useAutomationActionQuery: crud.useDetailQuery,
    useCreateAutomationActionMutation: crud.useCreateMutation,
    useUpdateAutomationActionMutation: crud.useUpdateMutation,
    useDeleteAutomationActionMutation: crud.useDeleteMutation,
  }
}
