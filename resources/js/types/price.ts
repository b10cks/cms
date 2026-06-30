/** Value stored by the `price` content field: currency code → amount (null when not filled in). */
export type PriceValue = Record<string, number | null>

/** All currencies present in a price value (base first, then additional). */
export const resolvePriceCurrencies = (schema: {
  base_currency: string
  currencies: string[]
}): string[] => {
  const additional = (schema.currencies ?? []).filter(
    (c) => c && c !== schema.base_currency
  )
  return schema.base_currency ? [schema.base_currency, ...additional] : additional
}

/**
 * Parse a price input to a number.
 * Accepts string or number because Vue's vModelText casts `<input type="number">` values
 * to JS numbers before emitting — so event handlers receive numbers, not strings.
 */
export const parsePriceAmount = (raw: string | number | null | undefined): number | null => {
  if (typeof raw === 'number') return Number.isFinite(raw) && raw >= 0 ? raw : null
  const trimmed = (raw ?? '').trim()
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null
}
