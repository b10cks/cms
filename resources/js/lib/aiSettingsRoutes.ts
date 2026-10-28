import type { AiOwner } from '~/api/resources/ai-knowledge'
import type { AiScope } from '~/api/resources/ai'

/**
 * Routes of the AI settings pages for a space or a team. Space pages live in
 * the space settings, team pages under the team's AI tab.
 */
export function aiSettingsRoutes(owner: AiOwner) {
  const isSpace = owner.kind === 'space'
  const params = isSpace ? { space: owner.id } : { team: owner.id }

  return {
    skills: { name: isSpace ? 'space-settings-ai-skills' : 'team-ai', params },
    knowledge: { name: isSpace ? 'space-settings-ai-knowledge' : 'team-ai', params },
    skill: (skillId: string) => ({
      name: isSpace ? 'space-settings-ai-skill' : 'team-ai-skill',
      params: { ...params, skillId },
    }),
    document: (documentId: string, query?: { type: string }) => ({
      name: isSpace ? 'space-settings-ai-document' : 'team-ai-document',
      params: { ...params, documentId },
      query,
    }),
    glossary: (glossaryId: string) => ({
      name: isSpace ? 'space-settings-ai-glossary' : 'team-ai-glossary',
      params: { ...params, glossaryId },
    }),
  }
}

/** Whether a listed skill, document or glossary belongs to the owner, rather than a team above it. */
export const isOwnedBy = (row: { scope: AiScope; owner_id: string }, owner: AiOwner) =>
  row.scope === owner.kind && row.owner_id === owner.id
