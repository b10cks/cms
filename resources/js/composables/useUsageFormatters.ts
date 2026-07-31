/**
 * Shared formatters for usage figures (bytes / counts / USD spend), used by the
 * subscription and usage-history pages so the display stays consistent.
 */
export function useUsageFormatters() {
  const { t, getLocale } = useI18n()

  function formatBytes(bytes: number | null | undefined): string {
    if (bytes == null) return t('labels.plans.unlimited')
    // Scale on magnitude, so a negative delta or remaining quota does not fall
    // through to MB — and keep scaling past GB.
    const magnitude = Math.abs(bytes)
    if (magnitude >= 1024 ** 4) {
      const tb = bytes / 1024 ** 4
      return `${tb.toFixed(Math.abs(tb) >= 10 ? 0 : 1)} TB`
    }
    if (magnitude >= 1024 ** 3) {
      const gb = bytes / 1024 ** 3
      return `${gb.toFixed(Math.abs(gb) >= 10 ? 0 : 1)} GB`
    }
    const mb = bytes / 1024 ** 2
    return `${mb.toFixed(0)} MB`
  }

  function formatNumber(n: number | null | undefined): string {
    if (n == null) return t('labels.plans.unlimited')
    // The app locale, not the browser's — useFormat uses it and these figures
    // sit next to each other on the usage pages.
    return new Intl.NumberFormat(getLocale()).format(n)
  }

  function formatUnit(value: number | null | undefined, unit: UsageUnit): string {
    if (value == null) return t('labels.plans.unlimited')
    if (unit === 'bytes') return formatBytes(value)
    if (unit === 'usd') {
      const magnitude = Math.abs(value)
      return new Intl.NumberFormat(getLocale(), {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        // Sub-cent precision on magnitude, so a small credit is not flattened
        // to -$0.00 while the same spend renders $0.0123.
        maximumFractionDigits: magnitude > 0 && magnitude < 1 ? 4 : 2,
      }).format(value)
    }
    return formatNumber(value)
  }

  return { formatBytes, formatNumber, formatUnit }
}
