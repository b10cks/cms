import type Echo from 'laravel-echo'

// laravel-echo already augments Window.Echo (broadly, as Echo<keyof Broadcaster>);
// this app only ever installs the reverb broadcaster, so narrow it here.
export const useEcho = (): Echo<'reverb'> | null => {
  return (window.Echo as Echo<'reverb'> | undefined) ?? null
}
