import type { MaybeRefOrGetter } from 'vue'

import { api } from '~/api'
import type { BackupsQueryParams } from '~/api/resources/backups'
import { createCrudComposable } from '~/lib/crud-composable'

import { queryKeys } from './useQueryClient'

const useBackupsCrud = createCrudComposable<
  BackupResource,
  ApiCollectionResponse<BackupResource>,
  BackupsQueryParams,
  CreateBackupPayload,
  UpdateBackupPayload
>({
  i18nKey: 'backups',
  keys: (spaceId) => queryKeys.backups(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).backups,
  defaultParams: { sort: 'created_at', order: 'desc' },
  toastValues: (data) => ({ name: data.name }),
  // A backup is built by a queued job, so a pending row has to poll itself done.
  detailRefetchInterval: (data) => (data?.state === 'pending' ? 2000 : false),
})

export function useBackups(spaceId: MaybeRefOrGetter<string>) {
  const crud = useBackupsCrud(spaceId)

  return {
    useBackupsQuery: crud.useListQuery,
    useBackupQuery: crud.useDetailQuery,
    useCreateBackupMutation: crud.useCreateMutation,
    useUpdateBackupMutation: crud.useUpdateMutation,
    useDeleteBackupMutation: crud.useDeleteMutation,
  }
}
