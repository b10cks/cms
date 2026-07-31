import { describe, expect, it } from 'vitest'

import useFileUtils from '~/composables/useFileUtils'

const { getFileType, getFileIcon } = useFileUtils()

describe('getFileType', () => {
  it.each([
    ['image/png', 'image'],
    ['image/svg+xml', 'image'],
    ['video/mp4', 'video'],
    ['audio/mpeg', 'audio'],
    ['application/pdf', 'document'],
  ])('maps %s to %s', (mimeType, expected) => {
    expect(getFileType(mimeType)).toBe(expected)
  })

  it.each([
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.oasis.opendocument.text',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  ])('recognises %s as a document', (mimeType) => {
    expect(getFileType(mimeType)).toBe('document')
  })

  // Legacy Office types name neither 'document' nor 'spreadsheet', so the
  // substring checks alone dropped .doc/.xls/.xlsm out of the document filter.
  it.each([
    'application/msword',
    'application/vnd.ms-excel',
    'application/vnd.ms-excel.sheet.macroEnabled.12',
    'application/vnd.ms-powerpoint',
  ])('recognises the legacy Office type %s as a document', (mimeType) => {
    expect(getFileType(mimeType)).toBe('document')
  })

  it.each(['text/plain', 'text/csv', 'application/zip', 'application/json', 'font/woff2'])(
    'falls back to other for %s',
    (mimeType) => {
      expect(getFileType(mimeType)).toBe('other')
    }
  )

  it.each(['', null, undefined])('returns other for the falsy mime type %s', (mimeType) => {
    expect(getFileType(mimeType as unknown as string)).toBe('other')
  })

  // Mime types are case-insensitive per RFC 2045 — and `macroEnabled.12` is
  // genuinely spelled with capitals.
  it('is case insensitive', () => {
    expect(getFileType('IMAGE/PNG')).toBe('image')
    expect(getFileType('Application/PDF')).toBe('document')
  })

  it('matches only on the prefix, so the subtype cannot smuggle a type in', () => {
    expect(getFileType('application/image/png')).toBe('other')
  })
})

describe('getFileIcon', () => {
  it.each([
    ['image', 'lucide:file-image'],
    ['document', 'lucide:file-text'],
    ['video', 'lucide:file-video'],
    ['audio', 'lucide:file-audio'],
    ['other', 'lucide:file'],
  ])('maps %s to %s', (type, expected) => {
    expect(getFileIcon(type)).toBe(expected)
  })

  it.each(['', 'archive', 'IMAGE'])('falls back to the generic icon for %s', (type) => {
    expect(getFileIcon(type)).toBe('lucide:file')
  })

  it('covers every type getFileType can return', () => {
    for (const mimeType of ['image/png', 'video/mp4', 'audio/mp3', 'application/pdf', 'text/plain']) {
      expect(getFileIcon(getFileType(mimeType))).toMatch(/^lucide:file/)
    }
  })
})
