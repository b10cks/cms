import type Echo from 'laravel-echo'

declare global {
  interface Window {
    Echo: Echo<'reverb'>
  }
}

export const useEcho = (): Echo<'reverb'> | null => {
  return window.Echo ?? null
}
