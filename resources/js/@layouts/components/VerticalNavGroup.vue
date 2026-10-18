<script lang="ts" setup>
import { TransitionGroup } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { layoutConfig } from '@layouts'
import { TransitionExpand, VerticalNavLink } from '@layouts/components'
import { canViewNavMenuGroup } from '@layouts/plugins/casl'
import { useLayoutConfigStore } from '@layouts/stores/config'
import { injectionKeyIsVerticalNavHovered, injectionKeyNavGroupParent } from '@layouts/symbols'
import type { NavGroup } from '@layouts/types'
import {
  closeSiblingNavGroups,
  getDynamicI18nProps,
  isNavGroupActive,
  openGroups,
  registerNavGroup,
  unregisterNavGroup,
} from '@layouts/utils'

defineOptions({
  name: 'VerticalNavGroup',
})

const props = defineProps<{
  item: NavGroup
}>()

// Use Inertia page URL instead of vue-router's useRoute/useRouter
const page = usePage()
const currentPath = computed(() => {
  try { return new URL(page.url, window.location.origin).pathname }
  catch { return window.location.pathname }
})

const configStore = useLayoutConfigStore()
const hideTitleAndBadge = configStore.isVerticalNavMini()

/*
  ℹ️ We provided default value `ref(false)` because inject will return `T | undefined`
  Docs: https://vuejs.org/api/composition-api-dependency-injection.html#inject
*/
const isVerticalNavHovered = inject(
  injectionKeyIsVerticalNavHovered,
  ref(false),
)

const isGroupActive = ref(false)
const isGroupOpen = ref(false)

/*
  Accordion, one level at a time.

  Each group learns its parent by injection and passes its own title down, which is what lets a
  module close the other modules in its category without touching the category itself. Registered
  for as long as the component is mounted so a sibling can close it directly — going through
  `openGroups` would not work, since that watcher deliberately refuses to close an active branch
  or one with an open child, which is precisely the branch you are navigating away from.
*/
const parentPath = inject(injectionKeyNavGroupParent, 'root')

// Identified by the whole chain, not the title: Asset Management is both a category and a module
// inside it, and two groups answering to one name cannot be told apart.
const groupPath = `${parentPath}/${props.item.title}`

provide(injectionKeyNavGroupParent, groupPath)

onMounted(() => registerNavGroup(groupPath, {
  parent: parentPath,
  close: () => { isGroupOpen.value = false },
}))

onUnmounted(() => unregisterNavGroup(groupPath))

/** Opening a group closes the ones beside it; closing it leaves everything else alone. */
function toggleGroup() {
  isGroupOpen.value = !isGroupOpen.value

  if (isGroupOpen.value)
    closeSiblingNavGroups(groupPath)
}

/**
 * Checks if any of children group is open or not.
 * This is helpful in preventing closing inactive parent group when inactive child group is opened. (i.e. Do not close "Nav Levels" group if child "Nav Level 2.2" is opened/clicked)
 *
 * @param {NavGroup['children']} children  - Nav group children
 * @return {boolean} returns if any of children is open or not.
 */
// `openGroups` holds menu paths, not titles: a category and a module inside it can share a title
// ("Assets" holds "Assets"), and by title closing the module also closed the category around it.
const isAnyChildOpen = (_children: NavGroup['children']): boolean =>
  openGroups.value.some(group => group.startsWith(`${groupPath}/`))

const collapseChildren = (_children: NavGroup['children']) => {
  openGroups.value = openGroups.value.filter(group => !group.startsWith(`${groupPath}/`))
}

/*
  Watch for URL changes (Inertia navigation replaces vue-router's route.path).
  Updates isActive & isOpen based on active state of group.
*/
watch(
  currentPath,
  () => {
    const isActive = isNavGroupActive(props.item.children)

    /*
      Open the group when the visit lands inside it, but never close it just because the new page
      is elsewhere. Landing on a page the menu can't match (a hub card's sub-page, a record view)
      used to collapse the whole branch under the user, so they had to re-open it every time.
      Groups still close when another branch opens — see the `openGroups` watcher below.
    */
    // Don't open group if vertical nav is collapsed and window size is more than overlay nav breakpoint
    if (isActive && !configStore.isVerticalNavMini(isVerticalNavHovered).value) {
      isGroupOpen.value = true

      // Landing inside a branch closes the ones beside it, the same as opening it by hand would.
      // Without this, navigating from a page in one category to a page in another leaves both
      // expanded — the branch you left never had a click to close it.
      closeSiblingNavGroups(groupPath)
    }

    isGroupActive.value = isActive
  },
  { immediate: true },
)

/*
  Watch for isGroupOpen

    1. Find group index for adding/removing group from openGroups array
    2. update openGroups array for addition/removal of current group

  We need `immediate: true` because without it initially opened group is not added in openGroups array
*/
watch(
  isGroupOpen,
  (val: boolean) => {
    // Find group index for adding/removing group from openGroups array
    const grpIndex = openGroups.value.indexOf(groupPath)

    // update openGroups array for addition/removal of current group

    // If group is opened => Add it to `openGroups` array
    if (val && grpIndex === -1) {
      openGroups.value.push(groupPath)
    }

    // If group is closed remove itself and its children from the `openGroups`
    else if (!val && grpIndex !== -1) {
      openGroups.value.splice(grpIndex, 1)
      collapseChildren(props.item.children)
    }
  },
  { immediate: true },
)

/*
  Watch for openGroups

  It will help in making vertical nav adapting the behavior of accordion.
  If we open multiple groups without navigating to any route we must close the inactive or temporarily opened groups.

  😵‍💫 Gotchas:
    * If we open inactive group then it will auto close that group because we close groups based on active state.
      Goal of this watcher is auto close groups which are not active when openGroups array is updated.
      So, we have to find a way to do not close recently opened inactive group.
      For this we will fetch recently added group in openGroups array and won't perform closing operation if recently added group is current group
*/
watch(
  openGroups,
  val => {
    // Prevent closing recently opened inactive group.
    const lastOpenedGroup = val.at(-1)
    if (lastOpenedGroup === groupPath)
      return

    const isActive = isNavGroupActive(props.item.children)

    // Goal of this watcher is to close inactive groups. So don't do anything for active groups.
    if (isActive)
      return

    // We won't close group if any of child group is open in current group
    if (isAnyChildOpen(props.item.children))
      return

    isGroupOpen.value = isActive
    isGroupActive.value = isActive
  },
  { deep: true },
)

// ℹ️ Previously instead of below watcher we were using two individual watcher for `isVerticalNavHovered`, `isVerticalNavCollapsed` & `isLessThanOverlayNavBreakpoint`
watch(configStore.isVerticalNavMini(isVerticalNavHovered), val => {
  isGroupOpen.value = val ? false : isGroupActive.value
})
</script>

<template>
  <li
    v-if="canViewNavMenuGroup(item)"
    class="nav-group"
    :class="[
      {
        active: isGroupActive,
        open: isGroupOpen,
        disabled: item.disable,
      },
    ]"
  >
    <div
      class="nav-group-label"
      @click="toggleGroup"
    >
      <Component
        :is="layoutConfig.app.iconRenderer || 'div'"
        v-bind="
          item.icon && typeof item.icon === 'object' && item.icon !== null
            ? item.icon
            : layoutConfig.verticalNav.defaultNavItemIconProps || {}
        "
        class="nav-item-icon"
      />

      <Component
        :is="TransitionGroup"
        name="transition-slide-x"
      >
        <!-- 👉 Title -->
        <Component
          :is="layoutConfig.app.i18n.enable ? 'i18n-t' : 'span'"
          v-bind="getDynamicI18nProps(item.title, 'span')"
          v-show="!hideTitleAndBadge"
          key="title"
          class="nav-item-title"
          :title="item.title"
        >
          {{ item.title }}
        </Component>

        <!-- 👉 Badge -->
        <Component
          :is="layoutConfig.app.i18n.enable ? 'i18n-t' : 'span'"
          v-bind="getDynamicI18nProps(item.badgeContent, 'span')"
          v-show="!hideTitleAndBadge"
          v-if="item.badgeContent"
          key="badge"
          class="nav-item-badge"
          :class="item.badgeClass"
        >
          {{ item.badgeContent }}
        </Component>
        <Component
          :is="layoutConfig.app.iconRenderer || 'div'"
          v-show="!hideTitleAndBadge"
          v-bind="layoutConfig.icons.chevronRight"
          key="arrow"
          class="nav-group-arrow"
        />
      </Component>
    </div>
    <TransitionExpand>
      <ul
        v-show="isGroupOpen"
        class="nav-group-children"
      >
        <Component
          :is="'children' in child ? 'VerticalNavGroup' : VerticalNavLink"
          v-for="child in item.children"
          :key="child.title"
          :item="child"
        />
      </ul>
    </TransitionExpand>
  </li>
</template>

<style lang="scss">
.layout-vertical-nav {
  .nav-group {
    &-label {
      display: flex;
      align-items: center;
      cursor: pointer;
    }
  }
}
</style>
