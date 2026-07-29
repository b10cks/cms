const downloadBlob = (blob: Blob, filename: string) => {
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = objectUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}

export const assetDownloadName = (asset: AssetResource): string => {
  return asset.extension ? `${asset.filename}.${asset.extension}` : asset.filename
}

export async function downloadAssetFile(asset: AssetResource): Promise<void> {
  const response = await fetch(asset.url ?? asset.full_path, { credentials: 'include' })

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}`)
  }

  downloadBlob(await response.blob(), assetDownloadName(asset))
}

export async function downloadAssetFiles(
  assets: AssetResource[],
  onProgress?: (done: number, total: number) => void
): Promise<{ succeeded: number; failed: string[] }> {
  const failed: string[] = []
  let done = 0

  for (const asset of assets) {
    try {
      await downloadAssetFile(asset)
    } catch {
      failed.push(assetDownloadName(asset))
    }

    done += 1
    onProgress?.(done, assets.length)
  }

  return { succeeded: assets.length - failed.length, failed }
}
