export const injectionKeyIsVerticalNavHovered: InjectionKey<Ref<boolean>> = Symbol('isVerticalNavHovered')

/**
 * Title of the enclosing nav group, or 'root' at the top level.
 *
 * A group renders its children without knowing what else sits beside it, so this is how one
 * learns which groups are its siblings — the set it must close when it opens.
 */
export const injectionKeyNavGroupParent: InjectionKey<string> = Symbol('navGroupParent')
