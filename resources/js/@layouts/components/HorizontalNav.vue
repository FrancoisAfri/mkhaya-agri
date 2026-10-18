<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { HorizontalNavGroup, HorizontalNavLink } from '@layouts/components'
import type { HorizontalNavItems, NavGroup, NavLink } from '@layouts/types'

/**
 * The category bar, with everything that will not fit collapsed into one "More" menu.
 *
 * This used to scroll: the overflowing categories were still there, behind a chevron at the end of
 * the bar. That hides navigation, which is the one thing a nav bar exists not to do — you cannot
 * look for what you cannot see, and the arrow is easy to miss entirely.
 *
 * Measuring rather than guessing a breakpoint is the point. Label widths differ per language by a
 * long way (Supply Chain against Chaîne d'appro. against Tshingaha tsha U Ṋekedza), a tenant can
 * add categories, and a person's window is whatever size they made it. Any fixed rule about "how
 * many fit" is wrong for someone.
 */
const props = defineProps<{
  navItems: HorizontalNavItems
}>()

const { t, te, locale } = useI18n()

const resolveNavItemComponent = (item: NavLink | NavGroup) => {
  if ('children' in item)
    return HorizontalNavGroup

  return HorizontalNavLink
}

// ── How many fit ─────────────────────────────────────────────────────────────

const navList = ref<HTMLElement | null>(null)

/** Right-hand edge of each item, in px from the start of the list. */
const itemEdges = ref<number[]>([])

const moreList = ref<HTMLElement | null>(null)

/**
 * Width of the "More" button.
 *
 * Starts at a sensible guess because the button does not exist until something overflows, and the
 * width is needed to decide whether anything does. Once it is on screen the real figure replaces
 * the guess and the count is redone — which settles in a frame, since the corrected width either
 * confirms the cut or moves it by one.
 */
const moreWidth = ref(120)

/** Infinity until the first measurement, so the bar renders whole rather than empty. */
const visibleCount = ref(Number.POSITIVE_INFINITY)

const visibleItems = computed(() => props.navItems.slice(0, visibleCount.value))
const overflowItems = computed(() => props.navItems.slice(visibleCount.value))

/**
 * The overflow menu is an ordinary nav group holding the leftovers.
 *
 * HorizontalNavGroup already renders itself recursively for nested groups, so building "More" as
 * a group gets the popper, the sub-menus and — usefully — the active highlight for free: it marks
 * itself active whenever the current page sits inside one of its children, so a category hidden in
 * here still shows you where you are.
 */
const moreItem = computed<NavGroup>(() => ({
  title: te('nav.more') ? t('nav.more') : 'More',
  icon: { icon: 'tabler-dots' },
  children: overflowItems.value,
}))

/**
 * Work out the cut from the measured edges.
 *
 * Edges are measured once with everything on screen and reused afterwards, because an item's width
 * comes from its own content and does not change when the window does. Only the language changing,
 * or the menu itself changing, makes them stale.
 */
function recompute() {
  const el = navList.value

  if (!el || itemEdges.value.length === 0)
    return

  const available = el.clientWidth
  const total = itemEdges.value.length

  // Everything fits: no menu, and no space wasted on a button nobody needs.
  if (itemEdges.value[total - 1] <= available) {
    visibleCount.value = total

    return
  }

  // Otherwise the "More" button has to fit too, so it comes out of the budget.
  const budget = available - moreWidth.value
  let count = 0

  while (count < total && itemEdges.value[count] <= budget)
    count++

  visibleCount.value = count
}

/** Render everything, read the layout, then apply the cut. */
async function measure() {
  visibleCount.value = Number.POSITIVE_INFINITY
  await nextTick()

  const el = navList.value

  if (!el)
    return

  const children = Array.from(el.children) as HTMLElement[]

  // offsetLeft + offsetWidth rather than summing widths: it accounts for whatever gap and margin
  // the theme puts between items, which summing silently would not.
  itemEdges.value = children.map(child => child.offsetLeft + child.offsetWidth)

  await nextTick()
  recompute()
}

let observer: ResizeObserver | null = null

onMounted(async () => {
  await measure()

  // Web fonts land after first paint and change every label's width, so one more pass once they
  // are ready — otherwise the cut is computed against the fallback font and is a little wrong.
  if (typeof document !== 'undefined' && 'fonts' in document)
    (document as any).fonts?.ready?.then(() => measure())

  if (typeof ResizeObserver !== 'undefined' && navList.value) {
    observer = new ResizeObserver(recompute)
    observer.observe(navList.value)
  }
})

onBeforeUnmount(() => observer?.disconnect())

// New labels or a new menu mean the old measurements describe something else.
watch(locale, () => measure())
watch(() => props.navItems, () => measure(), { deep: true })

// Replace the guessed button width with the real one the first time it is on screen.
watch(overflowItems, async () => {
  if (!overflowItems.value.length)
    return

  await nextTick()

  const width = moreList.value?.offsetWidth ?? 0

  // The 2px tolerance stops sub-pixel rounding from bouncing the count between two values.
  if (width > 0 && Math.abs(width - moreWidth.value) > 2) {
    moreWidth.value = width
    recompute()
  }
})
</script>

<template>
  <div class="nav-items-viewport">
    <ul
      ref="navList"
      class="nav-items"
    >
      <Component
        :is="resolveNavItemComponent(item)"
        v-for="(item, index) in visibleItems"
        :key="index"
        data-allow-mismatch
        :item="item"
      />
    </ul>

    <!--
      Kept outside the measured list on purpose: inside it, the button would be one of the things
      being measured and every recount would change the width it was counting against.
    -->
    <ul
      v-show="overflowItems.length"
      ref="moreList"
      class="nav-items nav-items--more"
    >
      <HorizontalNavGroup
        :item="moreItem"
        children-at-end
      />
    </ul>
  </div>
</template>

<style lang="scss">
.layout-wrapper.layout-nav-type-horizontal {
  .nav-items-viewport {
    display: flex;
    align-items: center;
    inline-size: 100%;
    min-inline-size: 0;
  }

  .nav-items {
    display: flex;
    flex-wrap: nowrap;
    flex-grow: 1;
    min-inline-size: 0;

    // Clipped, not scrollable: anything past the edge belongs in the More menu, and a bar that
    // both hides items and scrolls gives two ways to reach the same thing and confidence in
    // neither.
    overflow: hidden;

    // Every top-level item stays on one line at its natural width.
    > * {
      flex: 0 0 auto;
    }
  }

  .nav-items--more {
    flex: 0 0 auto;
    flex-grow: 0;
    overflow: visible;
  }
}
</style>
