// CASL stub — this project does not use CASL for permissions.
// All permission checks return true so all nav items are visible.
import type { NavGroup } from '@layouts/types'

/**
 * Stub: always returns true (no CASL configured)
 */
export const can = (_action: string | undefined, _subject: string | undefined): boolean => {
  return true
}

/**
 * Check if user can view item based on it's ability
 * Stub: always returns true when children exist
 */
export const canViewNavMenuGroup = (item: NavGroup): boolean => {
  return item.children.length > 0
}

/**
 * Stub: always returns true
 */
export const canNavigate = (_to: unknown): boolean => {
  return true
}
