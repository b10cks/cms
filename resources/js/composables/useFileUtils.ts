export default function useFileUtils() {
  // Legacy Office types name neither "document" nor "spreadsheet".
  const LEGACY_OFFICE = ['msword', 'ms-excel', 'ms-powerpoint', 'macroenabled']

  const getFileType = (mimeType: string): AssetTypes => {
    if (!mimeType) return 'other'
    // Mime types are case-insensitive (RFC 2045), and `macroEnabled.12` is
    // actually spelled with capitals.
    const mime = mimeType.toLowerCase()
    if (mime.startsWith('image/')) return 'image'
    if (mime.startsWith('video/')) return 'video'
    if (mime.startsWith('audio/')) return 'audio'
    if (
      mime === 'application/pdf' ||
      mime.includes('document') ||
      mime.includes('spreadsheet') ||
      mime.includes('presentation') ||
      LEGACY_OFFICE.some((token) => mime.includes(token))
    )
      return 'document'
    return 'other'
  }

  const getFileIcon = (type: AssetTypes | string) => {
    switch (type) {
      case 'image':
        return 'lucide:file-image'
      case 'document':
        return 'lucide:file-text'
      case 'video':
        return 'lucide:file-video'
      case 'audio':
        return 'lucide:file-audio'
      default:
        return 'lucide:file'
    }
  }

  return {
    getFileType,
    getFileIcon,
  }
}
