/**
 * Reads a folder tree out of a drag-and-drop payload or a directory-picker
 * FileList, so both paths produce the same shape: files with their relative
 * folder path plus the list of directories, including empty ones.
 *
 * A DataTransfer is only readable while the drop handler runs, so the entries
 * must be snapshotted synchronously with {@link snapshotDropEntries}; the
 * async traversal in {@link readDroppedTree} works on that snapshot.
 *
 * Pure DOM and Promise logic, no Vue, so Vitest can drive it directly.
 */

export interface DroppedFile {
  file: File
  /** Folder path relative to the drop target, POSIX separators, no filename. */
  path: string
}

export interface DroppedTree {
  files: DroppedFile[]
  /** Every directory encountered, in traversal order, including empty ones. */
  directories: string[]
  /** Entries filtered out: system junk, dotfiles and zero-byte files. */
  skipped: number
  /** Files the browser refused to hand over (offline volume, moved file). */
  unreadableFiles: number
  /**
   * Directories the browser refused to list. Counted apart from files because
   * the cost is not comparable: an unreadable directory can hide any number of
   * files, and it still gets created as an empty folder.
   */
  unreadableDirectories: number
}

export interface DropSnapshot {
  entries: FileSystemEntry[]
  /** Items the browser exposed without an entry (no folder support). */
  files: File[]
}

/** Mirrors `asset_folders.name` being varchar(100) on the server. */
export const FOLDER_NAME_MAX_LENGTH = 100

const JUNK_NAMES = new Set(['thumbs.db', '__macosx'])

const isJunkName = (name: string): boolean =>
  name.startsWith('.') || JUNK_NAMES.has(name.toLowerCase())

/**
 * macOS hands Chrome decomposed names. The server compares NFC, so every path
 * that leaves this module is NFC too and a dropped "Café" merges into the
 * "Café" that already exists.
 */
const toNfc = (value: string): string => value.normalize('NFC')

/**
 * The server truncates with `mb_substr`, which counts characters, so the
 * prediction has to count code points rather than UTF-16 units. `slice` would
 * halve a name of astral characters and could sever a surrogate pair.
 */
const truncateToCodePoints = (value: string, max: number): string =>
  Array.from(value).slice(0, max).join('')

/**
 * Client-side prediction of what the server will store for a path segment:
 * NFC-normalized, trimmed, truncated to the column length, placeholder when
 * nothing is left. The server additionally strips HTML; this only covers what
 * can be predicted without a purifier.
 */
export const normalizeFolderSegment = (segment: string): string => {
  const trimmed = truncateToCodePoints(toNfc(segment).trim(), FOLDER_NAME_MAX_LENGTH).trim()

  return trimmed === '' ? 'folder' : trimmed
}

/**
 * Captures the FileSystemEntry objects while the DataTransfer is still valid.
 * Must be called synchronously inside the drop handler.
 */
export const snapshotDropEntries = (dataTransfer: DataTransfer): DropSnapshot => {
  const entries: FileSystemEntry[] = []
  const files: File[] = []

  for (const item of Array.from(dataTransfer.items)) {
    if (item.kind !== 'file') {
      continue
    }

    const entry = typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null

    if (entry) {
      entries.push(entry)
      continue
    }

    const file = item.getAsFile()

    if (file) {
      files.push(file)
    }
  }

  return { entries, files }
}

const readAllEntries = async (
  directory: FileSystemDirectoryEntry
): Promise<{ entries: FileSystemEntry[]; failed: boolean }> => {
  const reader = directory.createReader()
  const entries: FileSystemEntry[] = []

  // readEntries yields at most 100 entries per call; the rest only arrives by
  // calling it again on the same reader until it answers with an empty array.
  // Stopping after one call silently truncates large folders.
  for (;;) {
    let batch: FileSystemEntry[]

    try {
      batch = await new Promise<FileSystemEntry[]>((resolve, reject) => {
        reader.readEntries(resolve, reject)
      })
    } catch {
      // Keep whatever the directory already yielded rather than losing the drop.
      return { entries, failed: true }
    }

    if (batch.length === 0) {
      break
    }

    entries.push(...batch)
  }

  return { entries, failed: false }
}

const entryFile = (entry: FileSystemFileEntry): Promise<File> =>
  new Promise((resolve, reject) => entry.file(resolve, reject))

/**
 * Traverses a snapshot into the flat tree result. A single unreadable entry
 * never fails the whole traversal: it is counted so the pre-flight can tell the
 * user what did not make it, files and directories apart.
 */
export const readDroppedTree = async (snapshot: DropSnapshot): Promise<DroppedTree> => {
  const files: DroppedFile[] = []
  const directories: string[] = []
  let skipped = 0
  let unreadableFiles = 0
  let unreadableDirectories = 0

  const collect = (file: File, path: string) => {
    if (isJunkName(file.name) || file.size === 0) {
      skipped++
      return
    }

    files.push({ file, path })
  }

  const visit = async (entry: FileSystemEntry, parentPath: string): Promise<void> => {
    if (isJunkName(entry.name)) {
      skipped++
      return
    }

    const name = toNfc(entry.name)

    if (entry.isDirectory) {
      const path = parentPath ? `${parentPath}/${name}` : name
      directories.push(path)

      const { entries, failed } = await readAllEntries(entry as FileSystemDirectoryEntry)

      if (failed) {
        unreadableDirectories++
      }

      for (const child of entries) {
        await visit(child, path)
      }

      return
    }

    if (entry.isFile) {
      try {
        collect(await entryFile(entry as FileSystemFileEntry), parentPath)
      } catch {
        unreadableFiles++
      }
    }
  }

  for (const entry of snapshot.entries) {
    await visit(entry, '')
  }

  for (const file of snapshot.files) {
    collect(file, '')
  }

  return { files, directories, skipped, unreadableFiles, unreadableDirectories }
}

/**
 * Builds the same result from an `<input type="file" webkitdirectory>`
 * FileList (or a plain file input, where `webkitRelativePath` is empty and
 * everything lands at the root).
 */
export const readTreeFromFileList = (list: ArrayLike<File>): DroppedTree => {
  const files: DroppedFile[] = []
  const directorySet = new Set<string>()
  let skipped = 0

  for (const file of Array.from(list)) {
    const segments = (file.webkitRelativePath || '').split('/').filter(Boolean)
    const directorySegments = segments.slice(0, -1).map(toNfc)

    if (directorySegments.some(isJunkName)) {
      skipped++
      continue
    }

    // Ancestors are real directories even when their only files are junk;
    // the drop path collects such directories as empty ones too.
    directorySegments.forEach((_, index) => {
      directorySet.add(directorySegments.slice(0, index + 1).join('/'))
    })

    if (isJunkName(file.name) || file.size === 0) {
      skipped++
      continue
    }

    files.push({ file, path: directorySegments.join('/') })
  }

  return {
    files,
    directories: [...directorySet],
    skipped,
    unreadableFiles: 0,
    unreadableDirectories: 0,
  }
}
