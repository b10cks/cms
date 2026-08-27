import { describe, expect, it } from 'vitest'

import {
  normalizeFolderSegment,
  readDroppedTree,
  readTreeFromFileList,
  snapshotDropEntries,
  type DropSnapshot,
} from '~/lib/dropped-tree'

const makeFile = (name: string, size = 8): File =>
  new File([new Uint8Array(size)], name, { type: 'application/octet-stream' })

const fileEntry = (file: File): FileSystemEntry =>
  ({
    isFile: true,
    isDirectory: false,
    name: file.name,
    file: (resolve: (file: File) => void) => resolve(file),
  }) as unknown as FileSystemEntry

/** A file the browser hands back but refuses to read, e.g. an offline volume. */
const unreadableFileEntry = (name: string): FileSystemEntry =>
  ({
    isFile: true,
    isDirectory: false,
    name,
    file: (_resolve: (file: File) => void, reject: (error: Error) => void) =>
      reject(new Error('NotReadableError')),
  }) as unknown as FileSystemEntry

/** A directory whose reader yields one batch and then fails. */
const failingDirEntry = (name: string, children: FileSystemEntry[]): FileSystemEntry =>
  ({
    isFile: false,
    isDirectory: true,
    name,
    createReader: () => {
      let served = false

      return {
        readEntries: (
          resolve: (entries: FileSystemEntry[]) => void,
          reject: (error: Error) => void
        ) => {
          if (served) {
            reject(new Error('NotReadableError'))
            return
          }

          served = true
          resolve(children)
        },
      }
    },
  }) as unknown as FileSystemEntry

/** A directory whose reader fails before yielding anything at all. */
const unreadableDirEntry = (name: string): FileSystemEntry =>
  ({
    isFile: false,
    isDirectory: true,
    name,
    createReader: () => ({
      readEntries: (_resolve: (entries: FileSystemEntry[]) => void, reject: (error: Error) => void) =>
        reject(new Error('NotReadableError')),
    }),
  }) as unknown as FileSystemEntry

const dropItem = (item: Partial<DataTransferItem>): DataTransferItem =>
  ({ kind: 'file', getAsFile: () => null, ...item }) as unknown as DataTransferItem

const dataTransfer = (items: DataTransferItem[]): DataTransfer =>
  ({ items }) as unknown as DataTransfer

/**
 * A faithful FileSystemDirectoryReader double: each readEntries call hands out
 * at most 100 entries and an empty array once drained, exactly like Chromium.
 */
const dirEntry = (name: string, children: FileSystemEntry[]): FileSystemEntry =>
  ({
    isFile: false,
    isDirectory: true,
    name,
    createReader: () => {
      let offset = 0

      return {
        readEntries: (resolve: (entries: FileSystemEntry[]) => void) => {
          const batch = children.slice(offset, offset + 100)
          offset += batch.length
          resolve(batch)
        },
      }
    },
  }) as unknown as FileSystemEntry

const snapshot = (entries: FileSystemEntry[], files: File[] = []): DropSnapshot => ({
  entries,
  files,
})

const withRelativePath = (file: File, relativePath: string): File => {
  Object.defineProperty(file, 'webkitRelativePath', { value: relativePath })

  return file
}

describe('readDroppedTree', () => {
  it('drains directories past the 100-entry readEntries cap', async () => {
    const children = Array.from({ length: 250 }, (_, index) =>
      fileEntry(makeFile(`file-${index}.png`))
    )

    const tree = await readDroppedTree(snapshot([dirEntry('Shoot', children)]))

    expect(tree.files).toHaveLength(250)
    expect(tree.skipped).toBe(0)
    expect(new Set(tree.files.map((entry) => entry.file.name)).size).toBe(250)
  })

  it('derives POSIX folder paths without the filename', async () => {
    const tree = await readDroppedTree(
      snapshot([
        dirEntry('Brand', [
          fileEntry(makeFile('logo.svg')),
          dirEntry('Logos', [fileEntry(makeFile('dark.svg'))]),
        ]),
        fileEntry(makeFile('loose.txt')),
      ])
    )

    expect(tree.files).toEqual([
      expect.objectContaining({ path: 'Brand' }),
      expect.objectContaining({ path: 'Brand/Logos' }),
      expect.objectContaining({ path: '' }),
    ])
    expect(tree.directories).toEqual(['Brand', 'Brand/Logos'])
  })

  it('collects empty directories', async () => {
    const tree = await readDroppedTree(
      snapshot([dirEntry('Brand', [dirEntry('Empty', []), dirEntry('AlsoEmpty', [])])])
    )

    expect(tree.files).toHaveLength(0)
    expect(tree.directories).toEqual(['Brand', 'Brand/Empty', 'Brand/AlsoEmpty'])
  })

  it('filters junk: dotfiles, Thumbs.db, __MACOSX and zero-byte files', async () => {
    const tree = await readDroppedTree(
      snapshot([
        dirEntry('Shoot', [
          fileEntry(makeFile('.DS_Store')),
          fileEntry(makeFile('Thumbs.db')),
          fileEntry(makeFile('.hidden')),
          fileEntry(makeFile('empty.txt', 0)),
          fileEntry(makeFile('keep.jpg')),
          dirEntry('__MACOSX', [fileEntry(makeFile('._keep.jpg'))]),
          dirEntry('.git', [fileEntry(makeFile('HEAD'))]),
        ]),
      ])
    )

    expect(tree.files.map((entry) => entry.file.name)).toEqual(['keep.jpg'])
    expect(tree.directories).toEqual(['Shoot'])
    expect(tree.skipped).toBe(6)
  })

  it('applies no file-type filter', async () => {
    const tree = await readDroppedTree(
      snapshot([
        fileEntry(makeFile('mock.psd')),
        fileEntry(makeFile('design.sketch')),
        fileEntry(makeFile('archive.zip')),
      ])
    )

    expect(tree.files).toHaveLength(3)
  })

  it('keeps fallback files from items without an entry at the root', async () => {
    const tree = await readDroppedTree(snapshot([], [makeFile('plain.pdf'), makeFile('.DS_Store')]))

    expect(tree.files.map((entry) => entry.file.name)).toEqual(['plain.pdf'])
    expect(tree.skipped).toBe(1)
  })

  it('keeps the rest of the drop when a single file cannot be read', async () => {
    const tree = await readDroppedTree(
      snapshot([
        dirEntry('Shoot', [
          fileEntry(makeFile('first.jpg')),
          unreadableFileEntry('gone.jpg'),
          fileEntry(makeFile('last.jpg')),
        ]),
      ])
    )

    expect(tree.files.map((entry) => entry.file.name)).toEqual(['first.jpg', 'last.jpg'])
    expect(tree.unreadableFiles).toBe(1)
    expect(tree.unreadableDirectories).toBe(0)
    expect(tree.skipped).toBe(0)
  })

  it('keeps what a directory already yielded when its reader fails', async () => {
    const tree = await readDroppedTree(
      snapshot([failingDirEntry('Shoot', [fileEntry(makeFile('kept.jpg'))])])
    )

    expect(tree.directories).toEqual(['Shoot'])
    expect(tree.files.map((entry) => entry.file.name)).toEqual(['kept.jpg'])
    expect(tree.unreadableDirectories).toBe(1)
    expect(tree.unreadableFiles).toBe(0)
  })

  it('reports a directory that yields nothing as an unreadable directory, not a file', async () => {
    const tree = await readDroppedTree(
      snapshot([
        dirEntry('Shoot', [unreadableDirEntry('Offline'), fileEntry(makeFile('kept.jpg'))]),
        unreadableFileEntry('gone.jpg'),
      ])
    )

    // The folder is still mirrored: an empty folder beats losing it silently.
    expect(tree.directories).toEqual(['Shoot', 'Shoot/Offline'])
    expect(tree.files.map((entry) => entry.file.name)).toEqual(['kept.jpg'])
    expect(tree.unreadableDirectories).toBe(1)
    expect(tree.unreadableFiles).toBe(1)
  })

  it('normalizes folder names to NFC so they match what the server compares', async () => {
    const tree = await readDroppedTree(
      snapshot([dirEntry('Cafe\u0301', [fileEntry(makeFile('menu.pdf'))])])
    )

    expect(tree.directories).toEqual(['Caf\u00e9'])
    expect(tree.files[0].path).toBe('Caf\u00e9')
  })
})

describe('snapshotDropEntries', () => {
  it('skips items that are not files', () => {
    const entry = fileEntry(makeFile('a.png'))
    const snapshot = snapshotDropEntries(
      dataTransfer([
        dropItem({ kind: 'string', webkitGetAsEntry: () => entry }),
        dropItem({ kind: 'file', webkitGetAsEntry: () => entry }),
      ])
    )

    expect(snapshot.entries).toEqual([entry])
    expect(snapshot.files).toEqual([])
  })

  it('falls back to getAsFile when the item exposes no entry', () => {
    const file = makeFile('plain.pdf')
    const snapshot = snapshotDropEntries(
      dataTransfer([
        dropItem({ webkitGetAsEntry: () => null, getAsFile: () => file }),
        dropItem({ webkitGetAsEntry: undefined, getAsFile: () => file }),
        dropItem({ webkitGetAsEntry: () => null, getAsFile: () => null }),
      ])
    )

    expect(snapshot.entries).toEqual([])
    expect(snapshot.files).toEqual([file, file])
  })

  it('reads the entries synchronously, before the DataTransfer is invalidated', async () => {
    const entry = dirEntry('Brand', [fileEntry(makeFile('logo.svg'))])
    let valid = true

    const snapshot = snapshotDropEntries(
      dataTransfer([
        dropItem({
          webkitGetAsEntry: () => {
            if (!valid) {
              throw new Error('the DataTransfer is gone once the handler returns')
            }

            return entry
          },
        }),
      ])
    )

    valid = false

    expect(snapshot.entries).toEqual([entry])
    expect((await readDroppedTree(snapshot)).files).toHaveLength(1)
  })
})

describe('readTreeFromFileList', () => {
  it('builds the same result from webkitRelativePath', () => {
    const tree = readTreeFromFileList([
      withRelativePath(makeFile('logo.svg'), 'Brand/logo.svg'),
      withRelativePath(makeFile('dark.svg'), 'Brand/Logos/dark.svg'),
      withRelativePath(makeFile('.DS_Store'), 'Brand/Logos/.DS_Store'),
      withRelativePath(makeFile('._x.jpg'), '__MACOSX/Brand/._x.jpg'),
      withRelativePath(makeFile('empty.png', 0), 'Brand/empty.png'),
    ])

    expect(tree.files).toEqual([
      expect.objectContaining({ path: 'Brand' }),
      expect.objectContaining({ path: 'Brand/Logos' }),
    ])
    expect(tree.directories).toEqual(['Brand', 'Brand/Logos'])
    expect(tree.skipped).toBe(3)
  })

  it('treats a plain file input as root files', () => {
    const tree = readTreeFromFileList([makeFile('a.png'), makeFile('b.png')])

    expect(tree.files.map((entry) => entry.path)).toEqual(['', ''])
    expect(tree.directories).toEqual([])
  })
})

describe('normalizeFolderSegment', () => {
  it('trims and truncates to the folder column length', () => {
    expect(normalizeFolderSegment('  Brand  ')).toBe('Brand')
    expect(normalizeFolderSegment('x'.repeat(150))).toBe('x'.repeat(100))
  })

  it('truncates by code point, matching the server mb_substr', () => {
    const truncated = normalizeFolderSegment('\u{1f600}'.repeat(150))

    expect(Array.from(truncated)).toHaveLength(100)
    expect(truncated).toBe('\u{1f600}'.repeat(100))
  })

  it('normalizes to NFC', () => {
    expect(normalizeFolderSegment('Cafe\u0301')).toBe('Caf\u00e9')
  })

  it('falls back to a placeholder when nothing is left', () => {
    expect(normalizeFolderSegment('   ')).toBe('folder')
  })
})
