import type { Router } from 'vue-router'
import { layoutConfig } from '@layouts/config'
import { AppContentLayoutNav } from '@layouts/enums'
import { useLayoutConfigStore } from '@layouts/stores/config'
import type { NavGroup, NavLink, NavLinkProps } from '@layouts/types'

export const openGroups = ref<string[]>([])

/**
 * Live nav groups, so opening one can close the others beside it.
 *
 * The menu is three levels deep — category, module, ribbon — and every branch stayed open once
 * visited, so a few clicks left a sidebar taller than the screen and you had to scroll past
 * Supply Chain to reach Operations. `openGroups` alone cannot fix that: it is a flat list of
 * titles with no notion of who sits beside whom, and closing on it would collapse a parent when
 * its own child opened.
 *
 * Keyed by path — "root/Operations & Support/Project Management" — rather than by title, because
 * titles are not unique across levels: Asset Management is both a category and a module inside it,
 * and a title-keyed registry would have the two overwrite each other.
 *
 * `parent` is the path of the enclosing group, or 'root' for a top-level category.
 */
type NavGroupHandle = { parent: string; close: () => void }

const navGroupRegistry = new Map<string, NavGroupHandle>()

export function registerNavGroup(path: string, handle: NavGroupHandle): void {
  navGroupRegistry.set(path, handle)
}

export function unregisterNavGroup(path: string): void {
  navGroupRegistry.delete(path)
}

/**
 * Close every group sitting beside this one — its siblings, never its ancestors.
 *
 * Scoping to a shared parent is the whole point: closing "everything else" would shut the
 * category you just opened a module inside of.
 */
export function closeSiblingNavGroups(path: string): void {
  const parent = navGroupRegistry.get(path)?.parent

  if (parent === undefined)
    return

  navGroupRegistry.forEach((handle, otherPath) => {
    if (otherPath !== path && handle.parent === parent)
      handle.close()
  })
}

/*
  Path of the nav ribbon that owns the current page, shared by the server as `nav_active_path`
  (see ViewServiceProvider). Hub cards live at URLs that are not ribbons of their own, so plain
  href matching finds nothing and the whole branch reads as inactive. MainLayout keeps this in
  sync on every Inertia visit.
*/
export const navActiveHref = ref<string | null>(null)

/**
 * Return nav link props to use
 // @param {Object, String} item navigation routeName or route Object provided in navigation data
 */

export const getComputedNavLinkToProp = computed(() => (link: NavLink) => {
  const props: NavLinkProps = {
    target: link.target,
    rel: link.rel,
  }

  // If route is string => it assumes string is route name => Create route object from route name
  // If route is not string => It assumes it's route object => returns passed route object
  if (link.to)
    props.to = typeof link.to === 'string' ? { name: link.to } : link.to
  else props.href = link.href

  return props
})

/**
 * Return route name for navigation link
 * If link is string then it will assume it is route-name
 * IF link is object it will resolve the object and will return the link
 // @param {Object, String} link navigation link object/string
 */
export const resolveNavLinkRouteName = (link: NavLink, router: Router) => {
  if (!link.to)
    return null

  if (typeof link.to === 'string')
    return link.to

  return router.resolve(link.to).name
}

/**
 * Check if nav-link is active
 * Uses href comparison against window.location.pathname (Inertia.js — no vue-router).
 * @param {object} link nav-link object
 * @param {Router} router optional, not used (kept for API compatibility)
 */
export const isNavLinkActive = (link: NavLink, router?: Router) => {
  // For href-based links (Inertia.js), compare against current pathname
  if (link.href) {
    if (typeof window === 'undefined')
      return false

    // The server resolved this page to a ribbon (hub card, sub-page of a hub, …)
    if (navActiveHref.value)
      return link.href === navActiveHref.value

    const currentPath = window.location.pathname
    // Exact match, or starts-with match for non-root paths
    if (link.href === '/')
      return currentPath === '/'
    return currentPath === link.href || currentPath.startsWith(`${link.href}/`)
  }

  // Fallback: if router is available (not typical in this project) use route matching
  if (router) {
    const matchedRoutes = router.currentRoute.value.matched
    const resolveRoutedName = resolveNavLinkRouteName(link, router)

    if (!resolveRoutedName)
      return false

    return matchedRoutes.some(route => {
      return route.name === resolveRoutedName || route.meta.navActiveLink === resolveRoutedName
    })
  }

  return false
}

/**
 * Check if nav group is active
 * @param {Array} children Group children
 * @param {Router} router optional
 */
export const isNavGroupActive = (children: (NavLink | NavGroup)[], router?: Router): boolean =>
  children.some(child => {
    // If child have children => It's group => Go deeper(recursive)
    if ('children' in child)
      return isNavGroupActive(child.children, router)

    // else it's link => Check for matched Route
    return isNavLinkActive(child, router)
  })

/**
 * Change `dir` attribute based on direction
 * @param dir 'ltr' | 'rtl'
 */
export const _setDirAttr = (dir: 'ltr' | 'rtl') => {
  // Check if document exists for SSR
  if (typeof document !== 'undefined')
    document.documentElement.setAttribute('dir', dir)
}

/**
 * Return dynamic i18n props based on i18n plugin is enabled or not
 * @param key i18n translation key
 * @param tag tag to wrap the translation with
 */
export const getDynamicI18nProps = (key: string, tag = 'span') => {
  if (!layoutConfig.app.i18n.enable)
    return {}

  return {
    keypath: key,
    tag,
    scope: 'global',
  }
}

export const switchToVerticalNavOnLtOverlayNavBreakpoint = () => {
  const configStore = useLayoutConfigStore()

  /*
      ℹ️ This is flag will hold nav type need to render when switching between lgAndUp from mdAndDown window width

      Requirement: When we nav is set to `horizontal` and we hit the `mdAndDown` breakpoint nav type shall change to `vertical` nav
      Now if we go back to `lgAndUp` breakpoint from `mdAndDown` how we will know which was previous nav type in large device?

      Let's assign value of `appContentLayoutNav` as default value of lgAndUpNav. Why 🤔?
        If template is viewed in lgAndUp
          We will assign `appContentLayoutNav` value to `lgAndUpNav` because at this point both constant is same
          Hence, for `lgAndUpNav` it will take value from theme config file
        else
          It will always show vertical nav and if user increase the window width it will fallback to `appContentLayoutNav` value
          But `appContentLayoutNav` will be value set in theme config file
    */
  const lgAndUpNav = ref(configStore.appContentLayoutNav)

  /*
      There might be case where we manually switch from vertical to horizontal nav and vice versa in `lgAndUp` screen
      So when user comes back from `mdAndDown` to `lgAndUp` we can set updated nav type
      For this we need to update the `lgAndUpNav` value if screen is `lgAndUp`
    */
  watch(
    () => configStore.appContentLayoutNav,
    value => {
      if (!configStore.isLessThanOverlayNavBreakpoint)
        lgAndUpNav.value = value
    },
  )

  /*
      This is layout switching part
      If it's `mdAndDown` => We will use vertical nav no matter what previous nav type was
      Or if it's `lgAndUp` we need to switch back to `lgAndUp` nav type. For this we will tracker property `lgAndUpNav`
    */
  watch(() => configStore.isLessThanOverlayNavBreakpoint, val => {
    configStore.appContentLayoutNav = val ? AppContentLayoutNav.Vertical : lgAndUpNav.value
  }, { immediate: true })
}
