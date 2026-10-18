import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

/**
 * Size an element to exactly the space left on the screen, so the page itself never scrolls.
 *
 * ── Why this is measured rather than calculated ──────────────────────────────
 *
 * The obvious version is `height: calc(100dvh - 260px)`, and that is what was here before. It is
 * wrong in a way that is easy to miss in development and obvious to a user: the page sits inside
 * the app shell, below a navbar and above a footer, each of its own height, and both change with
 * the theme, the density setting and whether the window is narrow enough to wrap. A subtraction
 * that is right on one screen is a scrollbar on the next, and the symptom — the whole page
 * scrolling, taking every column heading off the top — is the exact thing the fixed height exists
 * to prevent.
 *
 * So nothing is assumed. The element reports where its own top edge landed, the shell's footer
 * reports how much room it needs underneath, and what remains is the height. That holds whatever
 * the chrome above and below happens to be.
 *
 * The footer lookup is the one piece of coupling here, and it is deliberately a measurement rather
 * than a dependency: if the selector ever stops matching, the fallback is a page sized a footer too
 * tall, which is a small scroll — not a broken layout.
 */
export function useFitToViewport(gap = 8): { el: Ref<HTMLElement | null>; height: Ref<string> } {
  const el = ref<HTMLElement | null>(null)
  const height = ref<string>('auto')

  function measure() {
    const node = el.value
    if (!node) return

    /*
     * Written straight to the node, not through the reactive binding.
     *
     * The binding is applied on Vue's next flush, so clearing `height.value` here and reading the
     * box on the line below would measure the element still constrained to the previous answer —
     * the old value feeding into the new one, a few pixels adrift per resize. A direct style write
     * takes effect before the read on the next line does.
     */
    node.style.blockSize = 'auto'

    const top = node.getBoundingClientRect().top
    const footer = document.querySelector<HTMLElement>('.layout-footer')
    const footerHeight = footer ? footer.getBoundingClientRect().height : 0

    // innerHeight rather than a dvh unit: this is the live viewport, including whatever the mobile
    // browser chrome is doing at this moment, which is what a unit can only approximate.
    const available = window.innerHeight - top - footerHeight - gap

    // A floor rather than a negative height, for the narrow window where the chrome alone fills the
    // screen. Something scrollable beats something with no height at all.
    height.value = `${Math.max(available, 220)}px`
    node.style.blockSize = height.value
  }

  let observer: ResizeObserver | null = null

  onMounted(() => {
    measure()
    window.addEventListener('resize', measure)

    /*
     * The window is not the only thing that moves the top edge. Collapsing the sidebar, a flash
     * message appearing above the page, a navbar that wraps to two lines — none of those fire a
     * resize event, and every one of them changes where this element starts.
     */
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(() => measure())
      const wrapper = document.querySelector('.layout-content-wrapper')
      if (wrapper) observer.observe(wrapper)
    }
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', measure)
    observer?.disconnect()
    observer = null
  })

  return { el, height }
}
