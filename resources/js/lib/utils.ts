import { type ClassValue, clsx } from 'clsx'

export function cn(...inputs: ClassValue[]) {
  return clsx(inputs)
}

export async function digest(s: string) {
  const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s))
  const buffer = new Uint8Array(hash)

  return Array.from(buffer)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
}
