/**
 * A language's display name: the space's own name for it when the space
 * configures one, else the browser's name in the UI locale, else the code.
 */
export function languageName(
  code: string,
  languages: readonly SpaceLanguage[] | undefined,
  displayLocale: string
): string {
  const configured = languages?.find((language) => language.code.toLowerCase() === code.toLowerCase())
  if (configured?.name) return configured.name

  try {
    return new Intl.DisplayNames([displayLocale], { type: 'language' }).of(code) ?? code
  } catch {
    return code
  }
}
