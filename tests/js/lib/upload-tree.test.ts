import { describe, expect, it } from 'vitest'

import { buildUploadTree, type UploadTreeItem, type UploadTreeNode } from '~/lib/upload-tree'

const item = (folderPath: string, name: string): UploadTreeItem => ({
  folderPath,
  file: { name },
})

const findFolder = <T extends UploadTreeItem>(
  node: UploadTreeNode<T>,
  path: string
): UploadTreeNode<T> | undefined => {
  for (const child of node.folders) {
    if (child.path === path) {
      return child
    }

    const nested = findFolder(child, path)

    if (nested) {
      return nested
    }
  }

  return undefined
}

const folder = <T extends UploadTreeItem>(
  node: UploadTreeNode<T>,
  path: string
): UploadTreeNode<T> => {
  const found = findFolder(node, path)

  if (!found) {
    throw new Error(`no folder at "${path}"`)
  }

  return found
}

const names = (node: UploadTreeNode<UploadTreeItem>) => node.folders.map((child) => child.name)
const filenames = (node: UploadTreeNode<UploadTreeItem>) => node.files.map((file) => file.file.name)

describe('buildUploadTree', () => {
  it('returns an empty root for an empty drop', () => {
    const root = buildUploadTree([], [])

    expect(root.path).toBe('')
    expect(root.name).toBe('')
    expect(root.folders).toEqual([])
    expect(root.files).toEqual([])
    expect(root.fileCount).toBe(0)
  })

  it('keeps files dropped at the root on the root node', () => {
    const root = buildUploadTree([item('', 'a.png'), item('', 'b.png')], [])

    expect(filenames(root)).toEqual(['a.png', 'b.png'])
    expect(root.folders).toEqual([])
    expect(root.fileCount).toBe(2)
  })

  it('nests to arbitrary depth', () => {
    const root = buildUploadTree(
      [item('a/b/c/d', 'deep.png')],
      ['a', 'a/b', 'a/b/c', 'a/b/c/d']
    )

    expect(names(root)).toEqual(['a'])

    const deepest = folder(root, 'a/b/c/d')

    expect(deepest.name).toBe('d')
    expect(deepest.folders).toEqual([])
    expect(filenames(deepest)).toEqual(['deep.png'])
  })

  it('creates the intermediate folders a directory path implies', () => {
    const root = buildUploadTree([], ['a/b/c'])

    expect(names(root)).toEqual(['a'])
    expect(names(folder(root, 'a'))).toEqual(['b'])
    expect(names(folder(root, 'a/b'))).toEqual(['c'])
    expect(folder(root, 'a/b/c').fileCount).toBe(0)
  })

  it('shows empty folders as nodes', () => {
    const root = buildUploadTree([item('photos', 'a.png')], ['photos', 'photos/raw', 'empty'])

    expect(names(root)).toEqual(['empty', 'photos'])
    expect(folder(root, 'empty').files).toEqual([])
    expect(folder(root, 'empty').fileCount).toBe(0)
    expect(folder(root, 'photos/raw').fileCount).toBe(0)
  })

  it('counts files in nested folders towards every ancestor', () => {
    const root = buildUploadTree(
      [
        item('', 'root.png'),
        item('a', 'one.png'),
        item('a/b', 'two.png'),
        item('a/b/c', 'three.png'),
        item('other', 'four.png'),
      ],
      []
    )

    expect(root.fileCount).toBe(5)
    expect(folder(root, 'a').fileCount).toBe(3)
    expect(folder(root, 'a/b').fileCount).toBe(2)
    expect(folder(root, 'a/b/c').fileCount).toBe(1)
    expect(folder(root, 'other').fileCount).toBe(1)
  })

  it('orders folders and files by name regardless of input order', () => {
    const root = buildUploadTree(
      [item('zulu', 'z.png'), item('', 'b.png'), item('', 'a.png'), item('alpha', 'x.png')],
      ['zulu', 'alpha', 'Mike']
    )

    expect(names(root)).toEqual(['alpha', 'Mike', 'zulu'])
    expect(filenames(root)).toEqual(['a.png', 'b.png'])
  })

  it('builds the same tree whatever order the input arrives in', () => {
    const files = [item('a/b', 'two.png'), item('a', 'one.png'), item('', 'root.png')]
    const directories = ['a', 'a/b', 'a/c']

    const forwards = buildUploadTree(files, directories)
    const backwards = buildUploadTree([...files].reverse(), [...directories].reverse())

    expect(backwards).toEqual(forwards)
  })

  it('files a file whose folder was never listed as a directory', () => {
    const root = buildUploadTree([item('missing/from/list', 'a.png')], [])

    expect(folder(root, 'missing/from/list').fileCount).toBe(1)
    expect(root.fileCount).toBe(1)
  })

  it('ignores leading and trailing separators', () => {
    const root = buildUploadTree([item('/a/b/', 'a.png')], ['/a/'])

    expect(names(root)).toEqual(['a'])
    expect(names(folder(root, 'a'))).toEqual(['b'])

    const inner = folder(root, 'a/b')

    expect(inner.folders).toEqual([])
    expect(filenames(inner)).toEqual(['a.png'])
  })
})
