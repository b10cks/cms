import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AiSchedulePayload } from '~/api/resources/ai'
import { toastError } from '~/lib/toast-error'

/** Scheduled agents of a space, with the skills each scope type can run. */
export function useAiSchedules(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const key = computed(() => queryKeys.ai(toValue(spaceId)).schedules())
  const resource = () => api.forSpace(toValue(spaceId)).ai
  const refresh = () => queryClient.invalidateQueries({ queryKey: key.value })

  const schedulesQuery = useQuery({
    queryKey: key,
    queryFn: () => resource().getSchedules(),
    enabled: computed(() => !!toValue(spaceId)),
  })

  const saveMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string | null; payload: AiSchedulePayload }) =>
      id ? resource().updateSchedule(id, payload) : resource().createSchedule(payload),
    onSuccess: refresh,
  })

  const toggleMutation = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => resource().updateSchedule(id, { enabled }),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiSettings.schedules.saveFailed', error),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => resource().deleteSchedule(id),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiSettings.schedules.deleteFailed', error),
  })

  return { schedulesQuery, saveMutation, toggleMutation, deleteMutation }
}
