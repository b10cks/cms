import { api } from '~/api'
import type { FieldPluginsQueryParams } from '~/api/resources/field-plugins'
import { createCrudComposable } from '~/lib/crud-composable'
import type {
  CreateFieldPluginPayload,
  FieldPluginResource,
  UpdateFieldPluginPayload,
} from '~/types/field-plugins'

import { queryKeys } from './useQueryClient'

const useFieldPluginsCrud = createCrudComposable<
  FieldPluginResource,
  ApiCollectionResponse<FieldPluginResource>,
  FieldPluginsQueryParams,
  CreateFieldPluginPayload,
  UpdateFieldPluginPayload
>({
  i18nKey: 'fieldPlugins',
  keys: (spaceId) => queryKeys.fieldPlugins(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).fieldPlugins,
  defaultParams: { sort: '+name' },
  toastValues: (data) => ({ name: data.name }),
  // `handle` is immutable — content already stored against a plugin is keyed by
  // it. The type omits it, but a cast would otherwise put it on the wire and
  // leave the server as the only gatekeeper.
  prepareUpdate: (payload) => {
    const { handle: _handle, ...updatable } = payload as UpdateFieldPluginPayload & {
      handle?: string
    }
    return updatable
  },
})

export function useFieldPlugins(spaceId: MaybeRef<string>) {
  const crud = useFieldPluginsCrud(spaceId)

  return {
    useFieldPluginsQuery: crud.useListQuery,
    useFieldPluginQuery: crud.useDetailQuery,
    useCreateFieldPluginMutation: crud.useCreateMutation,
    useUpdateFieldPluginMutation: crud.useUpdateMutation,
    useDeleteFieldPluginMutation: crud.useDeleteMutation,
  }
}
