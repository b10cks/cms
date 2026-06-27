type ChangeType = 'added' | 'removed' | 'changed'

export interface ObjectChange {
  type: ChangeType
  path: string
  oldValue?: unknown
  newValue?: unknown
}

export function computeObjectDiff(
  oldObj: Record<string, unknown> | null | undefined,
  newObj: Record<string, unknown> | null | undefined,
  prefix = '',
): ObjectChange[] {
  const changes: ObjectChange[] = []
  const old = oldObj ?? {}
  const next = newObj ?? {}
  const allKeys = new Set([...Object.keys(old), ...Object.keys(next)])

  for (const key of allKeys) {
    const path = prefix ? `${prefix}.${key}` : key
    const oldVal = old[key]
    const newVal = next[key]

    if (!(key in old)) {
      changes.push({ type: 'added', path, newValue: newVal })
    } else if (!(key in next)) {
      changes.push({ type: 'removed', path, oldValue: oldVal })
    } else if (
      typeof oldVal === 'object' && oldVal !== null && !Array.isArray(oldVal) &&
      typeof newVal === 'object' && newVal !== null && !Array.isArray(newVal)
    ) {
      changes.push(
        ...computeObjectDiff(
          oldVal as Record<string, unknown>,
          newVal as Record<string, unknown>,
          path,
        ),
      )
    } else if (JSON.stringify(oldVal) !== JSON.stringify(newVal)) {
      changes.push({ type: 'changed', path, oldValue: oldVal, newValue: newVal })
    }
  }

  return changes
}
