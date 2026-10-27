/**
 * Display helpers for AI skills. Built-in skills have translated labels under
 * `components.aiActions.skills.<name>`; space and team skills show their name.
 */
export function aiSkillLabel(name: string, t: (key: string) => string): string {
  const key = `components.aiActions.skills.${name}`
  const label = t(key)
  if (label !== key) return label

  const words = name.replace(/-/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}
