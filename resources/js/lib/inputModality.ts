/** Marks the root with the last input, so a menu shows its keyboard ring only to keyboard users. */
export function trackInputModality(): void {
  const root = document.documentElement
  const mark = (input: 'keyboard' | 'pointer') => () => {
    if (root.dataset.input !== input) root.dataset.input = input
  }

  window.addEventListener('keydown', mark('keyboard'), { capture: true, passive: true })
  window.addEventListener('pointermove', mark('pointer'), { capture: true, passive: true })
  window.addEventListener('pointerdown', mark('pointer'), { capture: true, passive: true })
}
