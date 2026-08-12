import { api } from '~/api'
import type { DataSourcesQueryParams } from '~/api/resources/data-sources'
import { createCrudComposable } from '~/lib/crud-composable'
import type {
  CreateDataSourcePayload,
  DataSourceResource,
  UpdateDataSourcePayload,
} from '~/types/data-sources'

import { queryKeys } from './useQueryClient'

const useDataSourcesCrud = createCrudComposable<
  DataSourceResource,
  ApiCollectionResponse<DataSourceResource>,
  DataSourcesQueryParams,
  CreateDataSourcePayload,
  UpdateDataSourcePayload
>({
  i18nKey: 'dataSources',
  keys: (spaceId) => queryKeys.dataSources(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).dataSources,
  defaultParams: { sort: '+name' },
  toastValues: (data) => ({ name: data.name }),
})

export function useDataSources(spaceId: MaybeRef<string>) {
  const crud = useDataSourcesCrud(spaceId)

  return {
    // Queries
    useDataSourcesQuery: crud.useListQuery,
    useDataSourceQuery: crud.useDetailQuery,

    // Mutations
    useCreateDataSourceMutation: crud.useCreateMutation,
    useUpdateDataSourceMutation: crud.useUpdateMutation,
    useDeleteDataSourceMutation: crud.useDeleteMutation,
  }
}
