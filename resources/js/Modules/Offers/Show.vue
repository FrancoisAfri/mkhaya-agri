<script setup lang="ts">
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  offer: {
    id: number; uuid: string
    status: { value: string; label: string; color: string }
    position_title: string
    department: string | null
    start_date: string | null
    salary_amount: string | null
    salary_currency: string
    contract_type: string | null
    notes: string | null
    expires_at: string | null
    sent_at: string | null
    accepted_at: string | null
    declined_at: string | null
    decline_reason: string | null
    candidate: { id: number; uuid: string; full_name: string; email: string } | null
    job: { id: number; title: string } | null
    created_by: { id: number; name: string } | null
    approved_by: { id: number; name: string } | null
    created_at: string
  }
}>()

// ─── Portal link ──────────────────────────────────────────────────────────────
const portalUrl = computed(() => `/portal/offers/${props.offer.uuid}`)

function copyPortalLink() {
  navigator.clipboard.writeText(window.location.origin + portalUrl.value)
}

// ─── Action forms ─────────────────────────────────────────────────────────────
const sendForm = useForm({})
function sendOffer() {
  sendForm.post(route('recruitment.offers.send', [props.offer.job?.id, props.offer.id]))
}

const withdrawForm = useForm({})
function withdrawOffer() {
  withdrawForm.post(route('recruitment.offers.withdraw', [props.offer.job?.id, props.offer.id]))
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatSalary(amount: string | null, currency: string): string {
  if (!amount) return '—'
  return `${currency} ${Number(amount).toLocaleString('en-ZA')} p.m.`
}

// ─── Timeline entries ─────────────────────────────────────────────────────────
type TimelineEntry = { dot: string; color: string; title: string; subtitle: string; active: boolean }

const timeline = computed<TimelineEntry[]>(() => {
  const entries: TimelineEntry[] = [
    {
      dot:      'tabler-circle-plus',
      color:    'default',
      title:    t('offers_pages.show.timeline.created'),
      subtitle: formatDate(props.offer.created_at),
      active:   true,
    },
    {
      dot:      'tabler-send',
      color:    props.offer.sent_at ? 'info' : 'grey-lighten-1',
      title:    t('offers_pages.show.timeline.sent_to_candidate'),
      subtitle: props.offer.sent_at ? formatDate(props.offer.sent_at) : t('offers_pages.show.timeline.pending'),
      active:   !!props.offer.sent_at,
    },
  ]

  if (props.offer.accepted_at) {
    entries.push({
      dot:      'tabler-circle-check',
      color:    'success',
      title:    t('offers_pages.show.timeline.accepted'),
      subtitle: formatDate(props.offer.accepted_at),
      active:   true,
    })
  } else if (props.offer.declined_at) {
    entries.push({
      dot:      'tabler-circle-x',
      color:    'error',
      title:    t('offers_pages.show.timeline.declined'),
      subtitle: formatDate(props.offer.declined_at),
      active:   true,
    })
  } else if (props.offer.expires_at && new Date(props.offer.expires_at) < new Date()) {
    entries.push({
      dot:      'tabler-clock-off',
      color:    'warning',
      title:    t('offers_pages.show.timeline.expired'),
      subtitle: formatDate(props.offer.expires_at),
      active:   true,
    })
  } else {
    entries.push({
      dot:      'tabler-circle-check',
      color:    'grey-lighten-1',
      title:    t('offers_pages.show.timeline.awaiting_response'),
      subtitle: props.offer.expires_at ? t('offers_pages.show.timeline.expires_label', { date: formatDate(props.offer.expires_at) }) : '—',
      active:   false,
    })
  }

  return entries
})
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4 flex-wrap">
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('offers.index'))"
      >
        {{ t('offers_pages.show.breadcrumb') }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ offer.position_title }}</span>
    </div>

    <!-- Page Header -->
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-2 mb-1 flex-wrap">
          <h4 class="text-h4 font-weight-bold">{{ offer.position_title }}</h4>
          <VChip :color="offer.status.color" size="small" variant="tonal" class="font-weight-medium">
            {{ offer.status.label }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ offer.candidate?.full_name ?? '—' }}
          <template v-if="offer.job"> &middot; {{ offer.job.title }}</template>
        </p>
      </div>
      <div class="d-flex align-center gap-2">
        <VBtn
          v-if="offer.status.value === 'draft'"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-send"
          :loading="sendForm.processing"
          @click="sendOffer"
        >
          {{ t('offers_pages.show.actions.send_offer') }}
        </VBtn>
        <VBtn
          v-if="offer.status.value === 'sent'"
          color="error"
          variant="tonal"
          prepend-icon="tabler-ban"
          :loading="withdrawForm.processing"
          @click="withdrawOffer"
        >
          {{ t('offers_pages.show.actions.withdraw') }}
        </VBtn>
        <VBtn
          v-if="offer.status.value === 'sent' || offer.status.value === 'accepted'"
          color="success"
          variant="tonal"
          prepend-icon="tabler-file-type-pdf"
          :href="route('offers.download-letter', [offer.job?.id, offer.id])"
          target="_blank"
        >
          {{ t('offers_pages.show.actions.download_letter') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Left column ─────────────────────────────────────────────────── -->
      <VCol cols="12" lg="8">

        <!-- Offer Terms card -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            {{ t('offers_pages.show.sections.offer_terms') }}
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div class="d-flex flex-column gap-3">
              <div class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.position') }}</span>
                <span class="text-body-2 font-weight-medium">{{ offer.position_title }}</span>
              </div>

              <VDivider />

              <div v-if="offer.department" class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.department') }}</span>
                <span class="text-body-2">{{ offer.department }}</span>
              </div>

              <VDivider v-if="offer.department" />

              <div class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.salary') }}</span>
                <span class="text-body-2 font-weight-medium">
                  {{ formatSalary(offer.salary_amount, offer.salary_currency) }}
                </span>
              </div>

              <VDivider />

              <div v-if="offer.contract_type" class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.contract_type') }}</span>
                <VChip size="x-small" variant="tonal" color="default" label class="text-capitalize">
                  {{ offer.contract_type }}
                </VChip>
              </div>

              <VDivider v-if="offer.contract_type" />

              <div class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.start_date') }}</span>
                <span class="text-body-2">{{ formatDate(offer.start_date) }}</span>
              </div>

              <VDivider />

              <div class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.expires') }}</span>
                <span class="text-body-2">{{ formatDate(offer.expires_at) }}</span>
              </div>

              <template v-if="offer.notes">
                <VDivider />
                <div>
                  <p class="text-caption text-medium-emphasis mb-1">{{ t('offers_pages.show.fields.notes') }}</p>
                  <p class="text-body-2" style="white-space: pre-wrap;">{{ offer.notes }}</p>
                </div>
              </template>
            </div>
          </VCardText>
        </VCard>

        <!-- Status Timeline card -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            {{ t('offers_pages.show.sections.status_timeline') }}
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <VTimeline side="end" density="compact" line-inset="8">
              <VTimelineItem
                v-for="(entry, i) in timeline"
                :key="i"
                :dot-color="entry.active ? entry.color : 'grey-lighten-2'"
                size="small"
              >
                <template #icon>
                  <VIcon :icon="entry.dot" size="16" />
                </template>
                <div class="d-flex flex-column">
                  <span
                    class="text-body-2 font-weight-medium"
                    :class="entry.active ? '' : 'text-medium-emphasis'"
                  >
                    {{ entry.title }}
                  </span>
                  <span class="text-caption text-medium-emphasis">{{ entry.subtitle }}</span>
                </div>
              </VTimelineItem>
            </VTimeline>

            <!-- Decline reason -->
            <VAlert
              v-if="offer.declined_at && offer.decline_reason"
              type="error"
              variant="tonal"
              density="compact"
              class="mt-3 text-body-2"
            >
              <strong>{{ t('offers_pages.show.timeline.decline_reason') }}:</strong> {{ offer.decline_reason }}
            </VAlert>
          </VCardText>
        </VCard>

      </VCol>

      <!-- ── Right sidebar ──────────────────────────────────────────────── -->
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">

          <!-- Candidate card -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('offers_pages.show.sections.candidate') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div v-if="offer.candidate" class="d-flex align-center gap-3 mb-4">
                <VAvatar size="48" color="primary" variant="tonal">
                  <span class="text-h6 font-weight-bold">
                    {{ offer.candidate.full_name[0].toUpperCase() }}
                  </span>
                </VAvatar>
                <div>
                  <p class="text-body-2 font-weight-semibold mb-0">{{ offer.candidate.full_name }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ offer.candidate.email }}</p>
                </div>
              </div>
              <p v-else class="text-body-2 text-medium-emphasis mb-4">{{ t('offers_pages.show.no_candidate') }}</p>
              <VBtn
                v-if="offer.candidate"
                variant="tonal"
                color="primary"
                block
                prepend-icon="tabler-user"
                @click="router.visit(route('recruitment.candidates.show', offer.candidate.id))"
              >
                {{ t('offers_pages.show.actions.view_profile') }}
              </VBtn>
            </VCardText>
          </VCard>

          <!-- Job card -->
          <VCard v-if="offer.job" elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('offers_pages.show.sections.job') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <p class="text-body-2 font-weight-medium mb-3">{{ offer.job.title }}</p>
              <VBtn
                variant="tonal"
                color="default"
                block
                prepend-icon="tabler-briefcase"
                @click="router.visit(route('recruitment.jobs.show', offer.job.id))"
              >
                {{ t('offers_pages.show.actions.view_job') }}
              </VBtn>
            </VCardText>
          </VCard>

          <!-- Portal link card (only when sent) -->
          <VCard v-if="offer.status.value === 'sent'" elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold d-flex align-center gap-2">
              <VIcon icon="tabler-link" size="18" color="info" />
              {{ t('offers_pages.show.sections.candidate_portal') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <p class="text-caption text-medium-emphasis mb-3">
                {{ t('offers_pages.show.portal.description') }}
              </p>
              <VTextField
                :model-value="portalUrl"
                :label="t('offers_pages.show.portal.label')"
                variant="outlined"
                density="comfortable"
                readonly
                append-inner-icon="tabler-copy"
                @click:append-inner="copyPortalLink"
              />
            </VCardText>
          </VCard>

          <!-- Meta info -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('offers_pages.show.sections.meta') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-2">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.created') }}</span>
                  <span class="text-body-2">{{ formatDate(offer.created_at) }}</span>
                </div>
                <div v-if="offer.created_by" class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.created_by') }}</span>
                  <span class="text-body-2">{{ offer.created_by.name }}</span>
                </div>
                <div v-if="offer.approved_by" class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('offers_pages.show.fields.approved_by') }}</span>
                  <span class="text-body-2">{{ offer.approved_by.name }}</span>
                </div>
              </div>
            </VCardText>
          </VCard>

        </div>
      </VCol>
    </VRow>
  </div>
</template>
