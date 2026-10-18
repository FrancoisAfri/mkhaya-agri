<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import StatusChip from '../Partials/StatusChip.vue'
import { date, dateTime, humanise, money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  pending: any[]
  mine: any[]
  noCompany?: boolean
}>()

// Overdue first: the queue is ordered by what is actually late, not by when it arrived.
const queue = computed(() => [...props.pending].sort((a, b) => {
  if (a.overdue !== b.overdue) return a.overdue ? -1 : 1
  return (b.waiting_days ?? 0) - (a.waiting_days ?? 0)
}))

const overdueCount = computed(() => props.pending.filter(a => a.overdue).length)
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.approvals.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.approvals.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="overdueCount" type="warning" variant="tonal"
        class="mb-4" icon="mdi-clock-alert-outline"
      >
        {{ t('procurement_governance.approvals.overdue_notice', { n: overdueCount }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-body-1 font-weight-bold py-3">
          {{ t('procurement_governance.approvals.awaiting_you') }}
        </VCardTitle>

        <div v-if="!queue.length" class="text-center py-10 px-4 text-medium-emphasis">
          <VIcon icon="mdi-check-all" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('procurement_governance.approvals.nothing_pending') }}</div>
        </div>

        <VList v-else density="comfortable">
          <VListItem
            v-for="item in queue" :key="item.step_id"
            :href="`/procurement-governance/approvals/${item.uuid}`"
            class="procgov-link-item"
          >
            <template #prepend>
              <VAvatar :color="item.overdue ? 'error' : 'warning'" variant="tonal" size="36">
                <VIcon :icon="item.overdue ? 'mdi-alert-outline' : 'mdi-clock-outline'" size="18" />
              </VAvatar>
            </template>

            <VListItemTitle class="text-body-2 font-weight-medium">
              {{ item.subject_label ?? item.subject_reference ?? humanise(item.subject_type) }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ item.name }}
              · {{ t('procurement_governance.approvals.step_of', { n: item.step_no, total: item.total_steps }) }}
              <template v-if="item.amount"> · {{ money(item.amount) }}</template>
              <template v-if="item.waiting_days !== null">
                · {{ t('procurement_governance.approvals.waiting_days', { n: item.waiting_days }) }}
              </template>
            </VListItemSubtitle>

            <template #append>
              <div class="text-end">
                <div v-if="item.due_at" class="text-caption" :class="item.overdue ? 'text-error' : 'text-medium-emphasis'">
                  {{ t('procurement_governance.approvals.due') }} {{ date(item.due_at) }}
                </div>
                <div v-if="item.record_reference" class="text-caption text-medium-emphasis">
                  {{ item.record_reference }}
                </div>
              </div>
            </template>
          </VListItem>
        </VList>
      </VCard>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="text-body-1 font-weight-bold py-3">
          {{ t('procurement_governance.approvals.raised_by_you') }}
        </VCardTitle>

        <div v-if="!mine.length" class="text-center py-8 px-4 text-medium-emphasis">
          <div class="text-body-2">{{ t('procurement_governance.approvals.none_raised') }}</div>
        </div>

        <VList v-else density="compact">
          <VListItem
            v-for="item in mine" :key="item.uuid"
            :href="`/procurement-governance/approvals/${item.uuid}`" class="procgov-link-item"
          >
            <VListItemTitle class="text-body-2">
              {{ item.subject_label ?? item.subject_reference }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ t('procurement_governance.approvals.step_of', { n: item.current_step, total: item.total_steps }) }}
              · {{ dateTime(item.requested_at) }}
              <template v-if="item.amount"> · {{ money(item.amount) }}</template>
            </VListItemSubtitle>
            <template #append><StatusChip :status="item.status" /></template>
          </VListItem>
        </VList>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.procgov-link-item { cursor: pointer; }
</style>
