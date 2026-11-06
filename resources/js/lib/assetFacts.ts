type AssetFactsSource = Pick<AssetValue, 'filename' | 'extension' | 'size' | 'metadata'>

/** The file name an asset row shows, with its extension. */
export const assetFileName = ({ filename, extension }: AssetFactsSource): string =>
  extension && !filename.toLowerCase().endsWith(`.${extension.toLowerCase()}`)
    ? `${filename}.${extension}`
    : filename

/**
 * The one-line facts of an asset row: type, pixels and size ("JPG, 3501 × 2481, 1.9 MB").
 * Pixels only show when the value carries them.
 */
export const assetFacts = (
  asset: AssetFactsSource,
  formatSize: (bytes: number) => string
): string => {
  const { width, height } = asset.metadata ?? {}

  return [
    asset.extension?.toUpperCase(),
    width && height ? `${width} × ${height}` : null,
    asset.size ? formatSize(asset.size) : null,
  ]
    .filter(Boolean)
    .join(', ')
}

/** The stored value for a picked library asset. Pixels ride along so the row can show them. */
export const toAssetValue = (asset: AssetResource): AssetValue => {
  const { width, height } = asset.metadata ?? {}

  return {
    id: asset.id,
    type: 'asset',
    full_path: asset.full_path,
    extension: asset.extension,
    mime_type: asset.mime_type,
    size: asset.size,
    filename: asset.filename,
    ...(width && height ? { metadata: { width, height } } : {}),
    data: {},
  }
}
