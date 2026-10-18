<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import StatusChip from '../Partials/StatusChip.vue'
import { count, dateTime, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  snapshot: any | null
  departments: { id: number; name: string }[]
  noCompany?: boolean
  can: { configure: boolean }
}>()

/**
 * §3.5 asks for a traffic light. Colour alone would fail WCAG 1.4.1 and, more practically, a
 * compliance dashboard is read by people who print it — so every state carries an icon and a word
 * as well as a colour.
 */
const light = (status: string) => ({
  COMPLIANT: { colour: 'success', icon: 'mdi-check-circle', label: t('procurement_governance.compliance.compliant') },
  ATTENTION: { colour: 'warning', icon: 'mdi-alert', label: t('procurement_governance.compliance.attention') },
  NON_COMPLIANT: { colour: 'error', icon: 'mdi-close-circle', label: t('procurement_governance.compliance.non_compliant') },
  UNKNOWN: { colour: 'grey', icon: 'mdi-help-circle', label: t('procurement_governance.compliance.unknown') },
}[status] ?? { colour: 'grey', icon: 'mdi-help-circle', label: t('procurement_governance.compliance.unknown') })

const overall = computed(() => light(props.snapshot?.overall ?? 'UNKNOWN'))

// Worst first: nobody scrolls a compliance dashboard to find the red one.
const ordered = computed(() => {
  const rank: Record<string, number> = { NON_COMPLIANT: 0, ATTENTION: 1, UNKNOWN: 2, COMPLIANT: 3 }

  return [...(props.snapshot?.checks ?? [])].sort((a, b) => rank[a.status] - rank[b.status])
})

/** A short, readable description of what the check found, per item type. */
function itemLabel(check: any, item: any): string {
  return item.reference
    ?? item.title
    ?? item.entity_reference
    ?? item.subject_reference
    ?? item.name
    ?? humanise(item.entity_type ?? '')
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.compliance.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.compliance.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany && snapshot">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-4 pa-4">
          <VAvatar :color="overall.colour" variant="tonal" size="64">
            <VIcon :icon="overall.icon" size="32" />
          </VAvatar>
          <div class="min-width-0">
            <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium">
              {{ t('procurement_governance.compliance.overall') }}
            </div>
            <div class="text-h5 font-weight-bold" :class="`text-${overall.colour}`">{{ overall.label }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ t('procurement_governance.compliance.as_at', { at: dateTime(snapshot.generated_at) }) }}
            </div>
          </div>
          <VSpacer />
          <div class="d-flex ga-2 flex-wrap">
            <VChip
              v-for="status in ['NON_COMPLIANT', 'ATTENTION', 'UNKNOWN', 'COMPLIANT']" :key="status"
              :color="light(status).colour" variant="tonal" label
            >
              <VIcon :icon="light(status).icon" start size="16" />
              {{ count(snapshot.counts[status] ?? 0) }} {{ light(status).label }}
            </VChip>
          </div>
        </VCardText>
      </VCard>

      <VRow>
        <VCol v-for="check in ordered" :key="check.key" cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="d-flex align-start ga-3 py-3">
              <VIcon :icon="light(check.status).icon" :color="light(check.status).colour" size="22" class="mt-1" />
              <div class="min-width-0 flex-grow-1">
                <div class="text-body-1 font-weight-bold">{{ check.label }}</div>
                <div class="text-caption" :class="`text-${light(check.status).colour}`">
                  {{ light(check.status).label }}
                  <template v-if="check.count"> · {{ count(check.count) }}</template>
                </div>
              </div>
            </VCardTitle>
            <VCardText class="pt-0">
              <div v-if="check.message" class="text-body-2 text-medium-emphasis mb-2">{{ check.message }}</div>

              <div v-if="!check.items?.length && !check.message" class="text-body-2 text-medium-emphasis">
                {{ t('procurement_governance.compliance.nothing_outstanding') }}
              </div>

              <!-- Naming the affected records is what turns a number into something actionable. -->
              <VList v-else-if="check.items?.length" density="compact" class="py-0">
                <VListItem v-for="(item, i) in check.items.slice(0, 6)" :key="i" class="px-0">
                  <VListItemTitle class="text-body-2">{{ itemLabel(check, item) }}</VListItemTitle>
                  <VListItemSubtitle v-if="item.expires_at || item.due_at || item.status" class="text-caption">
                    <template v-if="item.expires_at">{{ dateTime(item.expires_at) }}</template>
                    <template v-else-if="item.due_at">{{ dateTime(item.due_at) }}</template>
                    <template v-else-if="item.status">{{ humanise(item.status) }}</template>
                  </VListItemSubtitle>
                </VListItem>
                <VListItem v-if="check.items.length > 6" class="px-0">
                  <VListItemSubtitle class="text-caption">
                    {{ t('procurement_governance.compliance.and_more', { n: check.items.length - 6 }) }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VCard v-if="snapshot.recent_failures?.length" elevation="0" border rounded="lg" class="mt-4">
        <VCardTitle class="text-body-1 font-weight-bold py-3">
          {{ t('procurement_governance.compliance.recent_checks') }}
        </VCardTitle>
        <VList density="compact">
          <VListItem v-for="check in snapshot.recent_failures" :key="check.uuid">
            <template #prepend><StatusChip :status="check.result" /></template>
            <VListItemTitle class="text-body-2 ms-2">
              {{ check.subject_reference ?? humanise(check.subject_type) }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption ms-2">
              {{ dateTime(check.checked_at) }}
              · {{ t('procurement_governance.compliance.failures_warnings', {
                f: check.failures, w: check.warnings,
              }) }}
              <template v-if="check.rule_set_code"> · {{ check.rule_set_code }}</template>
            </VListItemSubtitle>
          </VListItem>
        </VList>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.min-width-0 { min-inline-size: 0; }
</style>
