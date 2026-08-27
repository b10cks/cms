/**
 * Turns the flat result of a folder drop into the nested structure the upload
 * dialog renders: folder nodes holding their child folders and their files,
 * at whatever depth was dropped.
 *
 * Pure data, no Vue and no DOM, so Vitest can drive it directly.
 */

/** The part of a staged upload item this transform needs. */
export interface UploadTreeItem {
  /** Folder path relative to the drop target, POSIX separators, '' at the root. */
  folderPath: string
  file: { name: string }
}

export interface UploadTreeNode<TFile extends UploadTreeItem> {
  /** Folder path from the drop target. '' for the root node. */
  path: string
  /** Last segment of the path. '' for the root node. */
  name: string
  /** Child folders, sorted by name. */
  folders: UploadTreeNode<TFile>[]
  /** Files directly in this folder, sorted by filename. */
  files: TFile[]
  /** Files in this folder and in every folder below it. */
  fileCount: number
}

const segmentsOf = (path: string): string[] => path.split('/').filter(Boolean)

const makeNode = <TFile extends UploadTreeItem>(
  path: string,
  name: string
): UploadTreeNode<TFile> => ({ path, name, folders: [], files: [], fileCount: 0 })

const byName = (a: { name: string }, b: { name: string }): number => a.name.localeCompare(b.name)

/**
 * Builds the tree from the staged files and the dropped directory list.
 *
 * Directories are passed separately because empty ones have no file to hint at
 * them, and the drop promises to mirror what was dropped. Intermediate folders
 * missing from either list are created on the way down, so a directory list of
 * `['a/b/c']` alone still yields the full chain.
 *
 * Returns the root node, whose own `path` and `name` are empty; its files are
 * the ones dropped at the target itself.
 */
export const buildUploadTree = <TFile extends UploadTreeItem>(
  files: readonly TFile[],
  directories: readonly string[] = []
): UploadTreeNode<TFile> => {
  const root = makeNode<TFile>('', '')
  const index = new Map<string, UploadTreeNode<TFile>>([['', root]])

  const folderAt = (path: string): UploadTreeNode<TFile> => {
    const known = index.get(path)

    if (known) {
      return known
    }

    let current = root
    let walked = ''

    for (const segment of segmentsOf(path)) {
      walked = walked ? `${walked}/${segment}` : segment

      let next = index.get(walked)

      if (!next) {
        next = makeNode<TFile>(walked, segment)
        index.set(walked, next)
        current.folders.push(next)
      }

      current = next
    }

    return current
  }

  for (const directory of directories) {
    folderAt(directory)
  }

  for (const file of files) {
    folderAt(file.folderPath).files.push(file)
  }

  // Sorting and counting in one pass down the tree: a folder's count is its own
  // files plus whatever its children reported.
  const finish = (node: UploadTreeNode<TFile>): number => {
    node.folders.sort(byName)
    node.files.sort((a, b) => byName(a.file, b.file))
    node.fileCount = node.files.length

    for (const child of node.folders) {
      node.fileCount += finish(child)
    }

    return node.fileCount
  }

  finish(root)

  return root
}
