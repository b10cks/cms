export default function useFileUtils() {
  const getFileType = (mimeType: string): AssetTypes => {
    if (!mimeType) return 'other'
    if (mimeType.startsWith('image/')) return 'image'
    if (mimeType.startsWith('video/')) return 'video'
    if (mimeType.startsWith('audio/')) return 'audio'
    if (
      mimeType === 'application/pdf' ||
      mimeType.includes('document') ||
      mimeType.includes('spreadsheet') ||
      mimeType.includes('presentation')
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
