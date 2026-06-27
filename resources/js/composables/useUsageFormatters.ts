/**
 * Shared formatters for usage figures (bytes / counts / USD spend), used by the
 * subscription and usage-history pages so the display stays consistent.
 */
export function useUsageFormatters() {
  const { t } = useI18n()

  function formatBytes(bytes: number | null | undefined): string {
    if (bytes == null) return t('labels.plans.unlimited')
    const gb = bytes / (1024 * 1024 * 1024)
    if (gb >= 1) return `${gb.toFixed(gb >= 10 ? 0 : 1)} GB`
    const mb = bytes / (1024 * 1024)
    return `${mb.toFixed(0)} MB`
  }

  function formatNumber(n: number | null | undefined): string {
    if (n == null) return t('labels.plans.unlimited')
    return new Intl.NumberFormat().format(n)
  }

  function formatUnit(value: number | null | undefined, unit: UsageUnit): string {
    if (value == null) return t('labels.plans.unlimited')
    if (unit === 'bytes') return formatBytes(value)
    if (unit === 'usd') {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: value > 0 && value < 1 ? 4 : 2,
      }).format(value)
    }
    return formatNumber(value)
  }

  return { formatBytes, formatNumber, formatUnit }
}
