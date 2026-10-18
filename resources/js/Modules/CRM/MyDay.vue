<script lang="ts" setup>
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Item { type: string; id: number; title: string; why: string; action: string; priority: string }
const props = defineProps<{
  aiEnabled: boolean
  worklist: { items: Item[] } | null
  counts: { tasks: number; reminders: number; deals: number; leads: number; notes: number; activities: number }
}>()

function generate() { router.post(route('crm.my-day.generate'), {}, { preserveScroll: true }) }

const prioColor: Record<string, string> = { high: 'error', medium: 'warning', low: 'blue-grey' }
const typeIcon: Record<string, string> = { deal: 'tabler-target', lead: 'tabler-user-plus', task: 'tabler-checkbox', reminder: 'tabler-bell', activity: 'tabler-timeline', note: 'tabler-note' }
const link = (it: Item) => {
  try { return { deal: route('crm.deals'), lead: route('crm.leads'), task: route('crm.activities'), reminder: route('crm.activities'), activity: route('crm.activities'), note: route('crm.activities') }[it.type] ?? '#' } catch { return '#' }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold"><VIcon icon="tabler-sun" color="warning" class="me-1" />{{ t('crm_my_day_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_my_day_index.description', { tasks: counts.tasks, reminders: counts.reminders, deals: counts.deals, leads: counts.leads, activities: counts.activities, notes: counts.notes }) }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-sparkles" :disabled="!aiEnabled" @click="generate">{{ t('crm_my_day_index.plan_my_day') }}</VBtn>
    </div>

    <VAlert v-if="!aiEnabled" type="warning" variant="tonal" class="mb-3">{{ t('crm_my_day_index.ai_disabled') }}</VAlert>

    <VCard v-if="worklist && worklist.items.length"><VList lines="two"><template v-for="(it, i) in worklist.items" :key="i"><VListItem :href="link(it)"><template #prepend><VAvatar :color="prioColor[it.priority] ?? 'grey'" variant="tonal"><VIcon :icon="typeIcon[it.type] ?? 'tabler-point'" /></VAvatar></template><VListItemTitle class="font-weight-medium">{{ i + 1 }}. {{ it.title }}</VListItemTitle><VListItemSubtitle><span class="text-medium-emphasis">{{ it.why }}</span><div class="text-body-2 mt-1"><VIcon icon="tabler-arrow-right" size="14" /> {{ it.action }}</div></VListItemSubtitle><template #append><VChip :color="prioColor[it.priority] ?? 'grey'" size="x-small" label class="text-capitalize">{{ t(`crm_my_day_index.priority.${it.priority}`) }}</VChip></template></VListItem><VDivider /></template></VList></VCard>
    <VCard v-else-if="worklist"><VCardText class="text-center text-medium-emphasis py-8">{{ t('crm_my_day_index.nothing_urgent') }}</VCardText></VCard>
    <VCard v-else><VCardText class="text-center text-medium-emphasis py-10"><VIcon icon="tabler-coffee" size="48" class="mb-2" /><div>{{ t('crm_my_day_index.click_to_plan') }}</div></VCardText></VCard>
  </div>
</template>