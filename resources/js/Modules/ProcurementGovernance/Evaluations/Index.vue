<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import StatusChip from '../Partials/StatusChip.vue'
import { date, daysUntil, humanise } from '../Partials/format'

const { t } = useI18n()

defineProps<{
  assignments: any[]
  noCompany?: boolean
}>()
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.evaluations.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.evaluations.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <div v-if="!noCompany && !assignments.length" class="text-center py-12 text-medium-emphasis">
      <VIcon icon="mdi-clipboard-check-outline" size="40" class="mb-3" />
      <div class="text-body-1">{{ t('procurement_governance.evaluations.none') }}</div>
      <div class="text-body-2">{{ t('procurement_governance.evaluations.none_hint') }}</div>
    </div>

    <VRow v-else-if="!noCompany">
      <VCol v-for="item in assignments" :key="item.evaluator_id" cols="12" md="6" lg="4">
        <VCard elevation="0" border rounded="lg" class="h-100 d-flex flex-column">
          <VCardText class="flex-grow-1">
            <div class="d-flex align-center justify-space-between ga-2 mb-2">
              <span class="text-caption font-weight-bold text-medium-emphasis">{{ item.reference }}</span>
              <StatusChip :status="item.status" />
            </div>
            <div class="text-body-1 font-weight-bold mb-1">{{ item.title }}</div>
            <div class="text-caption text-medium-emphasis mb-3">
              {{ t(`procurement_governance.tenders.methodologies.${item.methodology}`) }}
            </div>

            <!-- The declaration gate is stated before the work, because it blocks the work. -->
            <VAlert
              v-if="item.coi_status === 'NOT_COMPLETED'"
              type="warning" variant="tonal" density="compact" class="text-body-2 mb-3"
            >
              {{ t('procurement_governance.evaluations.declare_first') }}
            </VAlert>
            <VAlert
              v-else-if="item.coi_status === 'CONFLICT_IDENTIFIED'"
              type="error" variant="tonal" density="compact" class="text-body-2 mb-3"
            >
              {{ t('procurement_governance.evaluations.conflict_blocked') }}
            </VAlert>

            <div class="d-flex align-center justify-space-between text-caption mb-1">
              <span>{{ t('procurement_governance.evaluations.progress') }}</span>
              <span class="font-weight-medium">{{ item.submitted }} / {{ item.bidders }}</span>
            </div>
            <VProgressLinear
              :model-value="item.bidders ? (item.submitted / item.bidders) * 100 : 0"
              height="6" rounded color="primary"
              :aria-label="t('procurement_governance.evaluations.progress')"
            />

            <div v-if="item.evaluation_deadline" class="text-caption mt-3"
                 :class="item.overdue ? 'text-error font-weight-medium' : 'text-medium-emphasis'">
              <VIcon :icon="item.overdue ? 'mdi-alert-outline' : 'mdi-calendar-clock'" size="14" class="me-1" />
              {{ item.overdue
                ? t('procurement_governance.evaluations.overdue_since', { date: date(item.evaluation_deadline) })
                : t('procurement_governance.evaluations.due_in', { n: daysUntil(item.evaluation_deadline) }) }}
            </div>
          </VCardText>

          <VCardActions class="px-4 pb-4 pt-0">
            <Link :href="`/procurement-governance/evaluations/${item.review_uuid}`" class="w-100">
              <VBtn color="primary" variant="tonal" block>
                {{ item.coi_status === 'NOT_COMPLETED'
                  ? t('procurement_governance.evaluations.declare_and_start')
                  : t('procurement_governance.evaluations.open') }}
              </VBtn>
            </Link>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
