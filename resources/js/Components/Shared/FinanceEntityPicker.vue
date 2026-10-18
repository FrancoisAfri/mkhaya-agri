<script lang="ts" setup>
/**
 * Which of the tenant's businesses Accounting is showing.
 *
 * One tenant can trade under several names — Mkhaya MK, Mkhaya Move, Mkhaya Agri — each with its
 * own customers, suppliers, chart of accounts and letterhead. This sits in the app bar on every
 * Accounting screen for two reasons: so a list is never read as the wrong business's list, and so
 * switching does not mean navigating back to a setup page first.
 *
 * Nothing renders outside the module, or when the tenant has only one business: a control with a
 * single option is a label pretending to be a choice.
 */
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const page = usePage()

interface EntityOption { id: number; name: string; is_default: boolean; is_current: boolean }

const shared = computed(() => (page.props as any).financeEntity as
  { current: { id: number; name: string } | null; acrossAll: boolean; options: EntityOption[] } | null)

const options = computed<EntityOption[]>(() => shared.value?.options ?? [])
const current = computed(() => shared.value?.current ?? null)
const acrossAll = computed(() => Boolean(shared.value?.acrossAll))
const show = computed(() => options.value.length > 1)

/** What the button says: the business being worked in, or that all of them are being shown. */
const label = computed(() => acrossAll.value ? t('acc.entity_all') : (current.value?.name ?? ''))

/**
 * The group view belongs to the URL, so it is turned on and off by navigating.
 *
 * Switching business, by contrast, is a POST that is remembered — the two look alike in the menu
 * and are deliberately different underneath: one decides whose letterhead prints on a document,
 * the other is a way of reading figures.
 */
const groupViewUrl = computed(() => {
  const url = new URL(window.location.href)
  url.searchParams.set('all_businesses', '1')
  return url.pathname + url.search
})

function leaveGroupView() {
  const url = new URL(window.location.href)
  url.searchParams.delete('all_businesses')
  router.visit(url.pathname + url.search, { preserveScroll: true })
}

const open = ref(false)
const switching = ref<number | null>(null)

function choose(entity: EntityOption) {
  open.value = false

  if (!acrossAll.value && entity.id === current.value?.id)
    return

  switching.value = entity.id

  // A full visit, not a partial one: the page being looked at is showing the other business's
  // records, and every prop on it has to be re-read against the new selection.
  router.post(route('finance.accounting.entities.switch', { entity: entity.id }), {}, {
    preserveScroll: true,
    // Leaving the group view at the same time, or the page would switch business and still be
    // showing every business's figures.
    onSuccess: () => { if (acrossAll.value) leaveGroupView() },
    onFinish: () => { switching.value = null },
  })
}
</script>

<template>
  <VMenu v-if="show" v-model="open" :close-on-content-click="false" location="bottom start" offset="6">
    <template #activator="{ props: menuProps }">
      <VBtn
        v-bind="menuProps"
        variant="tonal"
        density="comfortable"
        class="ms-2 text-none"
        :aria-label="t('acc.entity_switch_aria', { name: label })"
      >
        <VIcon size="18" start>{{ acrossAll ? 'mdi-domain-plus' : 'mdi-domain' }}</VIcon>
        <span class="text-truncate" style="max-width: 180px;">{{ label }}</span>
        <VIcon size="16" end>mdi-chevron-down</VIcon>
      </VBtn>
    </template>

    <VCard min-width="260" class="py-1">
      <div class="px-4 pt-2 pb-1 text-overline text-medium-emphasis">{{ t('acc.entity_working_in') }}</div>

      <VList density="compact" nav>
        <VListItem
          v-for="entity in options"
          :key="entity.id"
          :active="entity.is_current"
          :disabled="switching !== null"
          @click="choose(entity)"
        >
          <template #prepend>
            <VIcon size="18" :color="entity.is_current ? 'primary' : undefined">
              {{ entity.is_current ? 'mdi-check-circle' : 'mdi-domain' }}
            </VIcon>
          </template>
          <VListItemTitle>{{ entity.name }}</VListItemTitle>
          <VListItemSubtitle v-if="entity.is_default">{{ t('acc.entity_default') }}</VListItemSubtitle>
          <template #append>
            <VProgressCircular v-if="switching === entity.id" indeterminate size="16" width="2" />
          </template>
        </VListItem>
      </VList>

      <VDivider class="my-1" />

      <!-- Reading the group. A link, not a switch: it changes this page, not what happens next. -->
      <VList density="compact" nav>
        <VListItem :active="acrossAll" :href="groupViewUrl" @click="open = false">
          <template #prepend>
            <VIcon size="18" :color="acrossAll ? 'primary' : undefined">
              {{ acrossAll ? 'mdi-check-circle' : 'mdi-domain-plus' }}
            </VIcon>
          </template>
          <VListItemTitle>{{ t('acc.entity_all') }}</VListItemTitle>
          <VListItemSubtitle>{{ t('acc.entity_all_hint') }}</VListItemSubtitle>
        </VListItem>
      </VList>

      <VDivider class="my-1" />

      <VListItem :href="route('finance.accounting.entities')" density="compact">
        <template #prepend><VIcon size="18">mdi-cog-outline</VIcon></template>
        <VListItemTitle class="text-body-2">{{ t('acc.entity_manage') }}</VListItemTitle>
      </VListItem>
    </VCard>
  </VMenu>
</template>
