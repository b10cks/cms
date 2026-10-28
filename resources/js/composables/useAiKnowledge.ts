import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type {
  AiGlossaryTermPayload,
  AiKnowledgeDocumentPayload,
  AiOwner,
  AiSkillPayload,
} from '~/api/resources/ai-knowledge'
import { toastError } from '~/lib/toast-error'

/**
 * Queries and mutations for the skills, knowledge documents and glossaries of
 * a space or team. Document and glossary lists include the team layers above
 * the owner, which are read-only here. Every mutation refreshes the owner's AI
 * queries.
 */
export function useAiKnowledge(owner: MaybeRefOrGetter<AiOwner>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const keys = computed(() => queryKeys.aiKnowledge(toValue(owner)))
  const resource = () => api.aiKnowledge(toValue(owner))

  const mutation = <TArgs, TResult>(
    fn: (args: TArgs) => Promise<TResult>,
    successKey: string | null,
    errorKey: string
  ) =>
    useMutation({
      mutationFn: fn,
      onSuccess: () => {
        void queryClient.invalidateQueries({ queryKey: keys.value.all() })
        if (successKey) toast.success(t(successKey))
      },
      onError: (error: unknown) => toastError(t, errorKey, error),
    })

  const useSkillsQuery = () =>
    useQuery({
      queryKey: computed(() => keys.value.skills()),
      queryFn: () => resource().skills(),
    })

  const useSkillQuery = (id: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => keys.value.skill(toValue(id) ?? '')),
      queryFn: async () => (await resource().skill(toValue(id) ?? '')).data,
      enabled: computed(() => !!toValue(id)),
    })

  const useDocumentsQuery = () =>
    useQuery({
      queryKey: computed(() => keys.value.documents()),
      queryFn: async () => (await resource().documents(true)).data,
      // Extraction runs on the queue; poll while a document waits for it.
      refetchInterval: (query) =>
        query.state.data?.some((doc) => doc.extraction_status === 'pending') ? 3000 : false,
    })

  const useDocumentQuery = (id: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => keys.value.document(toValue(id) ?? '')),
      queryFn: async () => (await resource().document(toValue(id) ?? '')).data,
      enabled: computed(() => !!toValue(id)),
      refetchInterval: (query) => (query.state.data?.extraction_status === 'pending' ? 3000 : false),
    })

  const useGlossariesQuery = () =>
    useQuery({
      queryKey: computed(() => keys.value.glossaries()),
      queryFn: async () => (await resource().glossaries(true)).data,
    })

  const useTermsQuery = (
    glossaryId: MaybeRefOrGetter<string | null>,
    search: MaybeRefOrGetter<string>
  ) =>
    useQuery({
      queryKey: computed(() => keys.value.terms(toValue(glossaryId) ?? '', toValue(search))),
      queryFn: async () => (await resource().terms(toValue(glossaryId) ?? '', toValue(search))).data,
      enabled: computed(() => !!toValue(glossaryId)),
    })

  return {
    useSkillsQuery,
    useSkillQuery,
    useDocumentsQuery,
    useDocumentQuery,
    useGlossariesQuery,
    useTermsQuery,
    useCreateSkillMutation: () =>
      mutation((payload: AiSkillPayload) => resource().createSkill(payload), 'composables.aiKnowledge.saved', 'composables.aiKnowledge.saveError'),
    useUpdateSkillMutation: () =>
      mutation(
        ({ id, payload }: { id: string; payload: AiSkillPayload }) => resource().updateSkill(id, payload),
        'composables.aiKnowledge.saved',
        'composables.aiKnowledge.saveError'
      ),
    useCustomizeSkillMutation: () =>
      mutation((name: string) => resource().customizeSkill(name), null, 'composables.aiKnowledge.saveError'),
    usePublishSkillMutation: () =>
      mutation((id: string) => resource().publishSkill(id), 'composables.aiKnowledge.published', 'composables.aiKnowledge.publishError'),
    useDeleteSkillMutation: () =>
      mutation((id: string) => resource().deleteSkill(id), 'composables.aiKnowledge.deleted', 'composables.aiKnowledge.deleteError'),
    useCreateDocumentMutation: () =>
      mutation(
        (payload: AiKnowledgeDocumentPayload) => resource().createDocument(payload),
        'composables.aiKnowledge.saved',
        'composables.aiKnowledge.saveError'
      ),
    useUpdateDocumentMutation: () =>
      mutation(
        ({ id, payload }: { id: string; payload: AiKnowledgeDocumentPayload }) =>
          resource().updateDocument(id, payload),
        'composables.aiKnowledge.saved',
        'composables.aiKnowledge.saveError'
      ),
    usePublishDocumentMutation: () =>
      mutation((id: string) => resource().publishDocument(id), 'composables.aiKnowledge.published', 'composables.aiKnowledge.publishError'),
    useUploadDocumentSourceMutation: () =>
      mutation(
        ({ id, file }: { id: string; file: File }) => resource().uploadDocumentSource(id, file),
        'composables.aiKnowledge.uploaded',
        'composables.aiKnowledge.saveError'
      ),
    useDeleteDocumentMutation: () =>
      mutation((id: string) => resource().deleteDocument(id), 'composables.aiKnowledge.deleted', 'composables.aiKnowledge.deleteError'),
    useCreateGlossaryMutation: () =>
      mutation((name: string) => resource().createGlossary({ name }), 'composables.aiKnowledge.saved', 'composables.aiKnowledge.saveError'),
    useUpdateGlossaryMutation: () =>
      mutation(
        ({ id, payload }: { id: string; payload: { name?: string; enabled?: boolean } }) =>
          resource().updateGlossary(id, payload),
        null,
        'composables.aiKnowledge.saveError'
      ),
    useDeleteGlossaryMutation: () =>
      mutation((id: string) => resource().deleteGlossary(id), 'composables.aiKnowledge.deleted', 'composables.aiKnowledge.deleteError'),
    useSaveTermMutation: () =>
      mutation(
        ({ glossaryId, id, payload }: { glossaryId: string; id: string | null; payload: AiGlossaryTermPayload }) =>
          id ? resource().updateTerm(glossaryId, id, payload) : resource().createTerm(glossaryId, payload),
        'composables.aiKnowledge.saved',
        'composables.aiKnowledge.saveError'
      ),
    useDeleteTermMutation: () =>
      mutation(
        ({ glossaryId, id }: { glossaryId: string; id: string }) => resource().deleteTerm(glossaryId, id),
        null,
        'composables.aiKnowledge.deleteError'
      ),
    useImportTermsMutation: () =>
      mutation(
        ({ glossaryId, file }: { glossaryId: string; file: File }) => resource().importTerms(glossaryId, file),
        'composables.aiKnowledge.imported',
        'composables.aiKnowledge.importError'
      ),
    exportUrl: (glossaryId: string, format: 'csv' | 'xlsx' | 'tbx') => resource().exportUrl(glossaryId, format),
  }
}
