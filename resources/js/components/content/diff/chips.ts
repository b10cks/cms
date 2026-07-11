type ChipStatus = 'added' | 'removed' | 'unchanged'

export function chipClasses(status: ChipStatus): string {
  switch (status) {
    case 'added':
      return 'border-success/30 bg-success/15 text-success'
    case 'removed':
      return 'border-destructive/30 bg-destructive/10 text-destructive line-through'
    default:
      return 'border-border text-muted-foreground'
  }
}

export type { ChipStatus }
