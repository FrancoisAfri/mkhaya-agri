<script setup lang="ts">
/**
 * What a contractor sees about their own standing, and the one thing they can do about it
 * (CON-18).
 *
 * Written for an administrator at a small contracting firm, not for a safety professional: no
 * module jargon, no status codes, and every refusal carries the reviewer's own words. The
 * usual experience this replaces is being turned away at a boom and told "your file is not in
 * order", which is unactionable — so the page leads with exactly what is outstanding and gives
 * them a way to send it.
 *
 * It is deliberately inside the supplier portal they already sign into. A second login for the
 * same customer is a login somebody forgets.
 */
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Gap = { type: string; label: string; state: string; expired_on: string | null }
type Doc = { uuid: string; type: string; label: string; title?: string | null
  reason?: string | null; expires_on?: string | null; days_left?: number
  supplied_on?: string | null }

const props = defineProps<{
  token: string
  supplierName: string
  hostName: string | null
  compliance: {
    contractor: { uuid: string; name: string; trade: string | null; status: string }
    score: { percent: number; met: number; total: number
      parts: { label: string; ok: boolean; kind: string }[] }
    missing: Gap[]
    rejected: Doc[]
    expiring: Doc[]
    accepted: Doc[]
    awaiting_review: Doc[]
    safety_file: {
      required: boolean; state: string | null; label: string; valid_until: string | null
      decision_reason: string | null; requested_changes: string[]; submitted_at: string | null
    }
    workers: { name: string; trade: string | null; inducted: boolean
      induction_expires: string | null
      credentials: { title: string; kind: string; status: string
        expires_on: string | null; expired: boolean }[] }[]
    sites: { site: string; status: string; reason: string | null
      conditions: string | null; valid_until: string | null }[]
    actions: { uuid: string; title: string; description: string | null
      due_date: string | null; status: string; priority: string; overdue: boolean }[]
    documentTypes: { value: string; title: string }[]
    required: string[]
    /** Null unless this contractor has appointed somebody (CON-25). */
    subcontractors: {
      visibility: string[]
      counts: { total: number; not_compliant: number }
      subcontractors: {
        uuid: string; name: string; trade: string | null; depth: number; is_active: boolean
        outcome?: string; allowed?: boolean; outstanding?: Record<string, number>
        documents?: string[]; safety_file?: string | null; workers?: number
        approved_sites?: string[]; incidents?: number
      }[]
    } | null
  } | null
}>()

const c = computed(() => props.compliance)

const subs = computed(() => c.value?.subcontractors ?? null)

const label = (value: string) =>
  value.toLowerCase().replace(/_/g, ' ').replace(/^./, ch => ch.toUpperCase())

const scoreColour = computed(() => {
  const p = c.value?.score.percent ?? 0
  return p >= 100 ? 'success' : p >= 60 ? 'warning' : 'error'
})

/** Everything outstanding, in one list, because that is the question they came with. */
const outstanding = computed(() => {
  if (!c.value) return []

  return [
    ...c.value.missing.map(m => ({
      key: `m-${m.type}`, label: m.label, type: m.type,
      note: m.state === 'AWAITING_REVIEW' ? t('sheq.portal.awaiting_review_note')
        : m.state === 'EXPIRED' ? t('sheq.portal.expired_on', { date: m.expired_on })
        : t('sheq.portal.never_supplied'),
      colour: m.state === 'AWAITING_REVIEW' ? 'info' : 'error',
      canUpload: m.state !== 'AWAITING_REVIEW',
    })),
    ...c.value.rejected.map(r => ({
      key: `r-${r.uuid}`, label: r.label, type: r.type,
      note: r.reason ? t('sheq.portal.rejected_because', { reason: r.reason })
        : t('sheq.portal.rejected'),
      colour: 'error', canUpload: true,
    })),
    ...c.value.expiring.map(e => ({
      key: `e-${e.uuid}`, label: e.label, type: e.type,
      note: t('sheq.portal.expires_in', { n: e.days_left, date: e.expires_on }),
      colour: 'warning', canUpload: true,
    })),
  ]
})

const workersNeedingInduction = computed(() =>
  (c.value?.workers ?? []).filter(w => !w.inducted).length)

const siteColour = (status: string) =>
  ({ APPROVED: 'success', BLOCKED: 'error', EXPIRED: 'warning' }[status] ?? 'default')

// ── Sending a document ────────────────────────────────────────────────────────────────────────
const dialog = ref(false)

const form = useForm({
  document_type: '' as string,
  title: '',
  reference: '',
  issuing_body: '',
  issued_on: '',
  expires_on: '',
  file: null as File | null,
})

function open(type?: string) {
  form.reset()
  form.clearErrors()
  form.document_type = type ?? (c.value?.documentTypes[0]?.value ?? '')
  dialog.value = true
}

function submit() {
  // forceFormData: a file cannot travel as JSON, and Inertia only switches automatically when
  // it spots one — a re-submit after a validation failure would otherwise send the wrong
  // encoding and lose the attachment.
  form.post(`/supplier-portal/${props.token}/safety/documents`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}
</script>

<template>
  <div class="pa-4 pa-md-6 mx-auto" style="max-width: 1100px">
    <div class="mb-6">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.portal.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ hostName ? t('sheq.portal.subtitle', { host: hostName }) : t('sheq.portal.title') }}
      </p>
    </div>

    <!-- Not every supplier works on a site. Saying so plainly beats an empty safety page,
         which reads as something having gone wrong. -->
    <VCard v-if="!c" elevation="0" border rounded="lg" class="pa-8 text-center">
      <VIcon icon="mdi-shield-check-outline" size="40" class="mb-3 text-medium-emphasis" />
      <div class="text-h6 font-weight-bold mb-1">{{ t('sheq.portal.not_a_contractor') }}</div>
      <p class="text-body-2 text-medium-emphasis mb-0 mx-auto" style="max-width: 460px">
        {{ t('sheq.portal.not_a_contractor_body') }}
      </p>
    </VCard>

    <template v-else>
      <VRow class="mb-1">
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg" class="pa-5 h-100">
            <div class="text-caption text-uppercase text-medium-emphasis mb-1">
              {{ t('sheq.portal.your_compliance') }}
            </div>
            <div class="d-flex align-center ga-4">
              <VProgressCircular :model-value="c.score.percent" :color="scoreColour"
                                 size="88" width="9">
                <span class="text-h6 font-weight-bold">{{ c.score.percent }}%</span>
              </VProgressCircular>
              <div>
                <div class="text-body-2 font-weight-medium">
                  {{ t('sheq.portal.met_of_total', {
                    met: c.score.met, total: c.score.total,
                  }) }}
                </div>
                <!-- The arithmetic, because a percentage nobody can explain invites "why is it
                     73" and there is no answer. -->
                <div class="text-caption text-medium-emphasis">
                  {{ c.score.total === 0 ? t('sheq.portal.nothing_required')
                                         : t('sheq.portal.score_explained') }}
                </div>
              </div>
            </div>
          </VCard>
        </VCol>

        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg" class="pa-5 h-100">
            <div class="text-caption text-uppercase text-medium-emphasis mb-2">
              {{ t('sheq.portal.where_you_stand') }}
            </div>
            <div class="d-flex flex-wrap ga-4">
              <div>
                <div class="text-h5 font-weight-bold"
                     :class="outstanding.length ? 'text-error' : 'text-success'">
                  {{ outstanding.length }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('sheq.portal.outstanding_items') }}
                </div>
              </div>
              <div>
                <div class="text-h5 font-weight-bold">{{ c.awaiting_review.length }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('sheq.portal.with_them') }}
                </div>
              </div>
              <div>
                <div class="text-h5 font-weight-bold"
                     :class="workersNeedingInduction ? 'text-warning' : ''">
                  {{ workersNeedingInduction }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('sheq.portal.workers_to_induct') }}
                </div>
              </div>
              <div>
                <div class="text-h5 font-weight-bold"
                     :class="c.actions.length ? 'text-warning' : ''">
                  {{ c.actions.length }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('sheq.portal.open_actions') }}
                </div>
              </div>
            </div>
          </VCard>
        </VCol>
      </VRow>

      <!-- ── What to do ──────────────────────────────────────────────────────────────────── -->
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3 d-flex align-center ga-2">
          {{ t('sheq.portal.what_we_need') }}
          <VChip v-if="outstanding.length" size="x-small" color="error" variant="tonal">
            {{ outstanding.length }}
          </VChip>
          <VSpacer />
          <VBtn v-if="c.documentTypes.length" color="primary" variant="flat" size="small"
                prepend-icon="mdi-upload" @click="open()">
            {{ t('sheq.portal.send_document') }}
          </VBtn>
        </VCardTitle>
        <VDivider />

        <div v-if="!outstanding.length" class="text-center py-10">
          <VIcon icon="mdi-check-circle-outline" size="32" color="success" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.portal.nothing_outstanding') }}</div>
        </div>

        <VTable v-else density="comfortable">
          <tbody>
            <tr v-for="item in outstanding" :key="item.key">
              <td class="py-3">
                <div class="text-body-2 font-weight-medium">{{ item.label }}</div>
                <div class="text-caption" :class="`text-${item.colour}`">{{ item.note }}</div>
              </td>
              <td style="width: 140px" class="text-end">
                <VBtn v-if="item.canUpload" size="small" variant="tonal"
                      prepend-icon="mdi-upload" @click="open(item.type)">
                  {{ t('sheq.portal.send') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <VRow>
        <VCol cols="12" md="6">
          <!-- ── Safety file ───────────────────────────────────────────────────────────── -->
          <VCard v-if="c.safety_file.required" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.portal.safety_file') }}
            </VCardTitle>
            <VDivider />
            <VCardText>
              <div class="d-flex align-center ga-2 mb-2">
                <VChip size="small" variant="tonal"
                       :color="c.safety_file.state === 'APPROVED' ? 'success'
                               : c.safety_file.state === null ? 'default' : 'warning'">
                  {{ c.safety_file.state
                    ? t('sheq.safety_file.state.' + c.safety_file.state, c.safety_file.state)
                    : t('sheq.portal.not_submitted') }}
                </VChip>
                <span v-if="c.safety_file.valid_until" class="text-caption text-medium-emphasis">
                  {{ t('sheq.contractor.site_until', { date: c.safety_file.valid_until }) }}
                </span>
              </div>

              <!-- The reviewer's own words. "Rejected" with nothing else sends the same file
                   back a second time. -->
              <VAlert v-if="c.safety_file.decision_reason" type="warning" variant="tonal"
                      density="compact" class="mb-2">
                {{ c.safety_file.decision_reason }}
              </VAlert>

              <ul v-if="c.safety_file.requested_changes.length" class="ps-4 text-body-2 mb-0">
                <li v-for="(change, i) in c.safety_file.requested_changes" :key="i">
                  {{ change }}
                </li>
              </ul>
            </VCardText>
          </VCard>

          <!-- ── Where you may work ────────────────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.portal.where_you_may_work') }}
            </VCardTitle>
            <VDivider />
            <VCardText v-if="!c.sites.length" class="text-body-2 text-medium-emphasis">
              {{ t('sheq.portal.no_site_decisions') }}
            </VCardText>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="site in c.sites" :key="site.site">
                  <td class="text-body-2">{{ site.site }}</td>
                  <td style="width: 120px">
                    <VChip size="x-small" variant="tonal" :color="siteColour(site.status)">
                      {{ t('sheq.contractor.site_status.' + site.status, site.status) }}
                    </VChip>
                  </td>
                  <td class="text-caption text-medium-emphasis">
                    <!-- Why they were refused, in the site's words, so it can be fixed or
                         disputed rather than only discovered. -->
                    <div v-if="site.reason">{{ site.reason }}</div>
                    <div v-if="site.conditions">
                      {{ t('sheq.contractor.site_conditions', { conditions: site.conditions }) }}
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <!-- ── Your people ───────────────────────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3 d-flex align-center ga-2">
              {{ t('sheq.portal.your_people') }}
              <VChip size="x-small" variant="tonal">{{ c.workers.length }}</VChip>
            </VCardTitle>
            <VDivider />
            <VCardText v-if="!c.workers.length" class="text-body-2 text-medium-emphasis">
              {{ t('sheq.portal.no_workers') }}
            </VCardText>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="worker in c.workers" :key="worker.name">
                  <td class="py-2">
                    <div class="text-body-2">{{ worker.name }}</div>
                    <div v-if="worker.trade" class="text-caption text-medium-emphasis">
                      {{ worker.trade }}
                    </div>
                    <div v-for="cred in worker.credentials" :key="cred.title"
                         class="text-caption" :class="cred.expired ? 'text-error' : 'text-medium-emphasis'">
                      {{ cred.title }}
                      <template v-if="cred.expires_on">
                        · {{ cred.expired ? t('sheq.portal.expired_on', { date: cred.expires_on })
                                          : cred.expires_on }}
                      </template>
                    </div>
                  </td>
                  <td style="width: 130px">
                    <VChip size="x-small" variant="tonal"
                           :color="worker.inducted ? 'success' : 'error'">
                      {{ worker.inducted ? t('sheq.portal.inducted')
                                         : t('sheq.portal.not_inducted') }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <!-- ── Actions ───────────────────────────────────────────────────────────────── -->
          <VCard v-if="c.actions.length" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.portal.actions_for_you') }}
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <tbody>
                <tr v-for="action in c.actions" :key="action.uuid">
                  <td class="py-2">
                    <div class="text-body-2">{{ action.title }}</div>
                    <div v-if="action.description" class="text-caption text-medium-emphasis">
                      {{ action.description }}
                    </div>
                  </td>
                  <td style="width: 130px" class="text-caption"
                      :class="action.overdue ? 'text-error' : 'text-medium-emphasis'">
                    <template v-if="action.due_date">
                      {{ action.overdue ? t('sheq.portal.overdue_since', { date: action.due_date })
                                        : t('sheq.portal.due', { date: action.due_date }) }}
                    </template>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>

      <!-- ── What is on file ─────────────────────────────────────────────────────────────── -->
      <VCard v-if="c.accepted.length || c.awaiting_review.length" elevation="0" border
             rounded="lg">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.portal.on_file') }}
        </VCardTitle>
        <VDivider />
        <VTable density="compact">
          <tbody>
            <tr v-for="doc in c.awaiting_review" :key="doc.uuid">
              <td class="text-body-2">{{ doc.label }}</td>
              <td style="width: 190px">
                <VChip size="x-small" variant="tonal" color="info">
                  {{ t('sheq.portal.awaiting_review') }}
                </VChip>
              </td>
            </tr>
            <tr v-for="doc in c.accepted" :key="doc.uuid">
              <td class="text-body-2">{{ doc.label }}</td>
              <td style="width: 190px" class="text-caption text-medium-emphasis">
                <VChip size="x-small" variant="tonal" color="success" class="me-2">
                  {{ t('sheq.portal.accepted') }}
                </VChip>
                <template v-if="doc.expires_on">{{ doc.expires_on }}</template>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- ── The firms this contractor appointed (CON-25) ──────────────────────────────────── -->
    <VCard v-if="subs && subs.subcontractors.length" elevation="0" border rounded="lg"
           class="mb-4">
      <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
        Firms you appointed
        <span class="text-caption text-medium-emphasis font-weight-regular">
          · {{ subs.counts.total }}
        </span>
      </VCardTitle>
      <VDivider />
      <VCardText class="pb-2">
        <!--
          Said plainly, because a principal contractor reading this needs to know it is their
          responsibility rather than the host's courtesy. Construction Regulation 7 makes the
          party that appoints a subcontractor answerable for taking reasonable steps to ensure
          they comply — and until this page existed, finding out meant waiting for an email.
        -->
        <p class="text-body-2 text-medium-emphasis mb-0">
          You are responsible for the compliance of the firms you appoint. This is their current
          standing as this site sees it.
        </p>
      </VCardText>

      <VAlert v-if="subs.counts.not_compliant" type="warning" variant="tonal" rounded="0"
              density="compact">
        {{ subs.counts.not_compliant }} of {{ subs.counts.total }}
        {{ subs.counts.not_compliant === 1 ? 'firm is' : 'firms are' }}
        not currently compliant. Their people may be refused at the gate.
      </VAlert>

      <VTable density="compact">
        <tbody>
          <tr v-for="sub in subs.subcontractors" :key="sub.uuid">
            <td class="py-2">
              <div class="d-flex align-center flex-wrap ga-2"
                   :style="{ paddingLeft: `${(sub.depth - 1) * 18}px` }">
                <!-- Indented, because a firm your subcontractor appointed is still in your
                     chain and a flat list makes it look like one you appointed directly. -->
                <VIcon v-if="sub.depth > 1" icon="mdi-subdirectory-arrow-right" size="14"
                       class="text-medium-emphasis" />
                <VChip v-if="sub.outcome" size="x-small" variant="tonal"
                       :color="sub.outcome === 'ALLOWED' ? 'success'
                         : sub.outcome === 'DENIED' ? 'error' : 'warning'">
                  {{ sub.outcome === 'ALLOWED' ? 'In order' : label(sub.outcome) }}
                </VChip>
                <span class="text-body-2">{{ sub.name }}</span>
                <span v-if="sub.trade" class="text-caption text-medium-emphasis">
                  {{ label(sub.trade) }}
                </span>
              </div>
              <div class="text-caption text-medium-emphasis mt-1"
                   :style="{ paddingLeft: `${(sub.depth - 1) * 18}px` }">
                <template v-if="sub.outstanding && Object.keys(sub.outstanding).length">
                  Outstanding:
                  <span v-for="(n, subject) in sub.outstanding" :key="subject">
                    {{ n }} {{ label(String(subject)) }}<!-- -->;
                  </span>
                </template>
                <template v-else-if="sub.outcome === 'ALLOWED'">
                  Nothing outstanding.
                </template>
                <template v-if="sub.safety_file">
                  · safety file {{ label(sub.safety_file) }}
                </template>
                <template v-if="sub.workers !== undefined">
                  · {{ sub.workers }} on the roster
                </template>
                <template v-if="sub.incidents !== undefined">
                  · {{ sub.incidents }} incidents
                </template>
                <template v-if="sub.approved_sites?.length">
                  · approved at {{ sub.approved_sites.join(', ') }}
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VDivider />
      <VCardText class="py-3">
        <!-- Why they are not being shown more. A contractor who thinks they are seeing
             everything will assume a quiet screen means a compliant subcontractor. -->
        <p class="text-caption text-medium-emphasis mb-0">
          {{ hostName ?? 'This site' }} decides how much of a subcontractor's record you are
          shown. Documents and certificates belonging to their staff are not included.
        </p>
      </VCardText>
    </VCard>

    <!-- ── Sending a document ────────────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="640" scrollable>
      <VCard v-if="c">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.portal.send_document') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <!-- Said before they send anything, so nobody waits at a gate expecting a document
               they have just uploaded to have cleared it. -->
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('sheq.portal.review_note') }}
          </VAlert>

          <VSelect v-model="form.document_type" :items="c.documentTypes"
                   variant="outlined" density="comfortable" class="mb-2"
                   :label="t('sheq.portal.document_type')"
                   :error-messages="form.errors.document_type" />

          <VFileInput
            v-model="form.file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            variant="outlined" density="comfortable" class="mb-2"
            prepend-icon="" prepend-inner-icon="mdi-paperclip"
            :label="t('sheq.portal.the_file')"
            :hint="t('sheq.portal.file_hint')" persistent-hint
            :error-messages="form.errors.file"
          />

          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="form.issued_on" type="date" variant="outlined"
                          density="comfortable" :label="t('sheq.portal.issued_on')"
                          :error-messages="form.errors.issued_on" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.expires_on" type="date" variant="outlined"
                          density="comfortable" :label="t('sheq.portal.expires_on')"
                          :hint="t('sheq.portal.expires_hint')" persistent-hint
                          :error-messages="form.errors.expires_on" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.reference" variant="outlined" density="comfortable"
                          :label="t('sheq.portal.reference')"
                          :error-messages="form.errors.reference" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.issuing_body" variant="outlined" density="comfortable"
                          :label="t('sheq.portal.issuing_body')"
                          :error-messages="form.errors.issuing_body" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="dialog = false">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing"
                :disabled="form.processing || !form.file || !form.document_type"
                @click="submit">
            {{ t('sheq.portal.send') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
