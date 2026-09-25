export type ContentPickerItem = FlatContentMenuItem & { level: number }

const cache = new WeakMap<object, ContentPickerItem[]>()

export function contentPickerItems(
  menuData: Record<string, FlatContentMenuItem>
): ContentPickerItem[] {
  const cached = cache.get(menuData)
  if (cached) return cached

  const compare = (a: FlatContentMenuItem, b: FlatContentMenuItem) =>
    (a.position ?? 0) - (b.position ?? 0) ||
    (a.name || '').localeCompare(b.name || '') ||
    a.id.localeCompare(b.id)

  const children = new Map<string, FlatContentMenuItem[]>()
  const roots: FlatContentMenuItem[] = []
  const singles: FlatContentMenuItem[] = []

  for (const item of Object.values(menuData)) {
    if (item.pid) {
      const siblings = children.get(item.pid)
      if (siblings) siblings.push(item)
      else children.set(item.pid, [item])
    } else if (item.type === 'single') {
      singles.push(item)
    } else {
      roots.push(item)
    }
  }

  roots.sort(compare)
  singles.sort(compare)
  for (const siblings of children.values()) siblings.sort(compare)

  const result: ContentPickerItem[] = []
  const visit = (item: FlatContentMenuItem, level: number) => {
    result.push({ ...item, level })
    for (const child of children.get(item.id) ?? []) visit(child, level + 1)
  }

  for (const item of [...roots, ...singles]) visit(item, 0)
  cache.set(menuData, result)
  return result
}
