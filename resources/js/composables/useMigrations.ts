import type { MaybeRefOrGetter } from 'vue'

import { api } from '~/api'
import type { MigrationsQueryParams } from '~/api/resources/migrations'
import { createCrudComposable } from '~/lib/crud-composable'

import { queryKeys } from './useQueryClient'

const useMigrationsCrud = createCrudComposable<
  MigrationResource,
  ApiCollectionResponse<MigrationResource>,
  MigrationsQueryParams,
  CreateMigrationPayload,
  never
>({
  i18nKey: 'migrations',
  keys: (spaceId) => queryKeys.migrations(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).migrations,
  defaultParams: { sort: 'created_at', order: 'desc' },
  // A migration runs on the queue, so a live row has to poll itself done.
  detailRefetchInterval: (data) =>
    data?.state === 'pending' || data?.state === 'processing' ? 2000 : false,
})

export function useMigrations(spaceId: MaybeRefOrGetter<string>) {
  const crud = useMigrationsCrud(spaceId)

  return {
    useMigrationsQuery: crud.useListQuery,
    useMigrationQuery: crud.useDetailQuery,
    useCreateMigrationMutation: crud.useCreateMutation,
    useDeleteMigrationMutation: crud.useDeleteMutation,
  }
}
