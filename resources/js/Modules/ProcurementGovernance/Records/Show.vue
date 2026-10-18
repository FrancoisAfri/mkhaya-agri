<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { date, dateTime, humanise, money, score } from '../Partials/format'
// `router` drives the stage change below; `Link` handles ordinary navigation.

const { t } = useI18n()

const props = defineProps<{
  record: any
  procurement: any[]
  tender: { reviews: any[]; bidders: any[]; links: any[] }
  supplier: any[]
  contract: any[]
  deviations: any[]
  documents: any[]
  timeline: any[]
  compliance: any[]
  audit: any[]
  stages: string[]
  officers: { id: number; name: string }[]
  can: { edit: boolean; audit: boolean }
}>()

const tab = ref('overview')
const moving = ref(false)
const targetStage = ref(props.record?.stage ?? '')

const base = computed(() => `/procurement-governance/records/${props.record.uuid}`)

/** Every §6.1 section, with its count, so an empty one is visibly empty rather than missing. */
const sections = computed(() => [
  { key: 'procurement', label: t('procurement_governance.records.sections.procurement'), n: props.procurement?.length ?? 0 },
  { key: 'tender', label: t('procurement_governance.records.sections.tender'), n: props.tender?.reviews?.length ?? 0 },
  { key: 'supplier', label: t('procurement_governance.records.sections.supplier'), n: props.supplier?.length ?? 0 },
  { key: 'contract', label: t('procurement_governance.records.sections.contract'), n: props.contract?.length ?? 0 },
  { key: 'deviations', label: t('procurement_governance.records.sections.deviations'), n: props.deviations?.length ?? 0 },
  { key: 'documents', label: t('procurement_governance.records.sections.documents'), n: props.documents?.length ?? 0 },
])

function move() {
  router.post(`${base.value}/transition`, { stage: targetStage.value }, {
    preserveScroll: true,
    onSuccess: () => { moving.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <div class="d-flex align-center ga-2 mb-1 flex-wrap">
          <span class="text-caption font-weight-bold text-medium-emphasis">{{ record.reference }}</span>
          <StatusChip :status="record.stage" />
          <StatusChip v-if="record.status !== 'ACTIVE'" :status="record.status" />
        </div>
        <h1 class="text-h5 font-weight-bold">{{ record.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ money(record.estimated_value, record.currency) }}
          <template v-if="record.awarded_value">
            → {{ money(record.awarded_value, record.currency) }}
          </template>
          <template v-if="record.category"> · {{ record.category }}</template>
          <template v-if="record.financial_year_label"> · {{ record.financial_year_label }}</template>
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn v-if="can.edit" variant="tonal" size="small" prepend-icon="mdi-arrow-right-circle-outline"
              @click="moving = true">
          {{ t('procurement_governance.records.change_stage') }}
        </VBtn>
        <Link v-if="can.audit" :href="`${base}/audit`">
          <VBtn variant="text" size="small" prepend-icon="mdi-history">
            {{ t('procurement_governance.records.audit_trail') }}
          </VBtn>
        </Link>
      </div>
    </div>

    <!-- §4.4 / §6.1: the timeline, with how long each stage actually took. -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="text-body-1 font-weight-bold py-3">
        {{ t('procurement_governance.records.timeline') }}
      </VCardTitle>
      <VCardText class="pt-0">
        <div v-if="!timeline.length" class="text-body-2 text-medium-emphasis">
          {{ t('procurement_governance.records.no_timeline') }}
        </div>
        <VTimeline v-else density="compact" side="end" truncate-line="both">
          <VTimelineItem v-for="(event, i) in timeline" :key="i" dot-color="primary" size="x-small">
            <div class="text-body-2 font-weight-medium">
              <template v-if="event.from_stage">{{ humanise(event.from_stage) }} → </template>
              {{ humanise(event.to_stage) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ dateTime(event.occurred_at) }}
              <template v-if="event.actor"> · {{ event.actor }}</template>
              <template v-if="event.days_in_from_stage !== null">
                · {{ t('procurement_governance.records.days_in_previous', { n: event.days_in_from_stage }) }}
              </template>
            </div>
            <div v-if="event.note" class="text-caption mt-1">{{ event.note }}</div>
          </VTimelineItem>
        </VTimeline>
      </VCardText>
    </VCard>

    <VTabs v-model="tab" class="mb-4" density="comfortable" show-arrows>
      <VTab value="overview">{{ t('procurement_governance.records.sections.overview') }}</VTab>
      <VTab v-for="section in sections" :key="section.key" :value="section.key">
        {{ section.label }}
        <VChip v-if="section.n" size="x-small" class="ms-2" variant="tonal" label>{{ section.n }}</VChip>
      </VTab>
      <VTab value="compliance">{{ t('procurement_governance.records.sections.compliance') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="overview">
        <VRow>
          <VCol cols="12" md="6">
            <VCard elevation="0" border rounded="lg" class="h-100">
              <VCardTitle class="text-body-1 font-weight-bold py-3">
                {{ t('procurement_governance.records.details') }}
              </VCardTitle>
              <VCardText class="pt-0 text-body-2">
                <div v-for="row in [
                  ['category', record.category],
                  ['method', humanise(record.method)],
                  ['opened_at', date(record.opened_at)],
                  ['target_completion_at', date(record.target_completion_at)],
                  ['closed_at', date(record.closed_at)],
                  ['cost_centre', record.cost_centre],
                ]" :key="row[0] as string" class="d-flex justify-space-between py-1">
                  <span class="text-medium-emphasis">{{ t(`procurement_governance.records.${row[0]}`) }}</span>
                  <span>{{ row[1] ?? '—' }}</span>
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="12" md="6">
            <VCard elevation="0" border rounded="lg" class="h-100">
              <VCardTitle class="text-body-1 font-weight-bold py-3">
                {{ t('procurement_governance.records.recent_actions') }}
              </VCardTitle>
              <VList density="compact">
                <VListItem v-for="(event, i) in audit.slice(0, 10)" :key="i">
                  <VListItemTitle class="text-body-2">{{ event.summary }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ dateTime(event.occurred_at) }}
                    <template v-if="event.actor_label"> · {{ event.actor_label }}</template>
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <VWindowItem v-for="section in ['procurement', 'supplier', 'contract']" :key="section" :value="section">
        <VCard elevation="0" border rounded="lg">
          <div v-if="!(props as any)[section]?.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-link-variant-off" size="32" class="mb-2" />
            <div class="text-body-2">{{ t(`procurement_governance.records.no_${section}`) }}</div>
          </div>
          <VList v-else density="comfortable">
            <VListItem v-for="link in (props as any)[section]" :key="link.id">
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ link.label ?? link.entity_reference ?? humanise(link.link_role) }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ humanise(link.link_role) }} · {{ link.source_module }}
                <template v-if="link.amount"> · {{ money(link.amount) }}</template>
                <template v-if="link.state"> · {{ humanise(link.state) }}</template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <VWindowItem value="tender">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.records.sections.tender') }}
          </VCardTitle>
          <div v-if="!tender.reviews.length" class="text-center py-10 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.records.no_tender') }}</div>
          </div>
          <VList v-else density="comfortable">
            <VListItem
              v-for="review in tender.reviews" :key="review.id"
              :href="`/procurement-governance/tenders/${review.uuid}`" class="procgov-link-item"
            >
              <VListItemTitle class="text-body-2 font-weight-medium">{{ review.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ review.reference }} · {{ humanise(review.methodology) }}
                <template v-if="review.evaluation_deadline"> · {{ date(review.evaluation_deadline) }}</template>
              </VListItemSubtitle>
              <template #append><StatusChip :status="review.status" /></template>
            </VListItem>
          </VList>
        </VCard>

        <VCard v-if="tender.bidders.length" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.participating_suppliers') }}
          </VCardTitle>
          <div class="procgov-scroll">
            <VTable density="compact">
              <thead>
                <tr>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.rank') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.supplier') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.bid_amount') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.weighted') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(bidder, i) in tender.bidders" :key="i">
                  <td class="text-end">{{ bidder.overall_rank ?? '—' }}</td>
                  <td class="text-body-2">
                    {{ bidder.supplier_name }}
                    <div v-if="bidder.disqualification_reason" class="text-caption text-error">
                      {{ bidder.disqualification_reason }}
                    </div>
                  </td>
                  <td class="text-end text-body-2">{{ money(bidder.bid_amount) }}</td>
                  <td class="text-end text-body-2">{{ score(bidder.weighted_score) }}</td>
                  <td><StatusChip :status="bidder.status" /></td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VWindowItem>

      <VWindowItem value="deviations">
        <VCard elevation="0" border rounded="lg">
          <div v-if="!deviations.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-check-circle-outline" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.records.no_deviations') }}</div>
          </div>
          <VList v-else density="comfortable">
            <VListItem
              v-for="deviation in deviations" :key="deviation.id"
              :href="`/procurement-governance/deviations/${deviation.uuid}`" class="procgov-link-item"
            >
              <VListItemTitle class="text-body-2 font-weight-medium">{{ deviation.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ deviation.reference }} · {{ humanise(deviation.deviation_type_code) }}
                · {{ money(deviation.procurement_value) }}
              </VListItemSubtitle>
              <template #append>
                <div class="d-flex ga-1">
                  <StatusChip :status="deviation.risk_level" size="x-small" />
                  <StatusChip :status="deviation.status" size="x-small" />
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <VWindowItem value="documents">
        <VCard elevation="0" border rounded="lg">
          <div v-if="!documents.length" class="text-center py-10 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.common.no_documents') }}</div>
          </div>
          <VList v-else density="comfortable">
            <VListItem
              v-for="doc in documents" :key="doc.id"
              :href="`/procurement-governance/documents/${doc.uuid}`" class="procgov-link-item"
            >
              <VListItemTitle class="text-body-2">{{ doc.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ doc.category_name ?? '—' }} · {{ humanise(doc.confidentiality) }}
                · {{ t('procurement_governance.common.version_n', { n: doc.version_count }) }}
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <VWindowItem value="compliance">
        <VCard elevation="0" border rounded="lg">
          <div v-if="!compliance.length" class="text-center py-10 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.records.no_compliance') }}</div>
          </div>
          <VList v-else density="comfortable">
            <VListItem v-for="(check, i) in compliance" :key="i">
              <template #prepend><StatusChip :status="check.result" /></template>
              <VListItemTitle class="text-body-2 ms-2">
                {{ check.subject_reference ?? humanise(check.subject_type) }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption ms-2">
                {{ dateTime(check.checked_at) }}
                · {{ t('procurement_governance.records.rules_evaluated', { n: check.rules_evaluated }) }}
                <template v-if="check.rule_set_code"> · {{ check.rule_set_code }} v{{ check.rule_set_version }}</template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="moving" max-width="480">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.records.change_stage') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="targetStage"
            :items="stages.map(s => ({ title: humanise(s), value: s }))"
            :label="t('procurement_governance.records.stage')"
            variant="outlined" density="comfortable"
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="moving = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="move">{{ t('procurement_governance.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }
.procgov-link-item { cursor: pointer; }
</style>
