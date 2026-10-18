<script setup lang="ts">
/**
 * Management of change (MOC-1 to MOC-5).
 *
 * The register and the open change on one screen, because they are read together: the list says
 * three changes are waiting and the detail says what each is waiting FOR, and the second is the
 * only part anybody can act on.
 *
 * The blockers are shown as a list of sentences rather than a disabled button with a tooltip. A
 * change can be blocked for half a dozen reasons at once — an approval not granted, four
 * mandatory controls outstanding — and "cannot be implemented" with no reason is how somebody
 * makes the change anyway and records it afterwards, which is the exact behaviour this feature
 * exists to replace.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Row = {
  uuid: string
  reference: string
  title: string
  change_category: string
  change_nature: string
  risk_rating: string | null
  status: string
  planned_date: string | null
  implemented_at: string | null
  effectiveness_review_due: string | null
  effectiveness_result: string | null
  rejection_reason: string | null
  site_name: string | null
  contractor_name: string | null
  impacts_total: number
  impacts_outstanding: number
  blocking_outstanding: number
  review_overdue: boolean
}

type Impact = {
  uuid: string
  impact_type: string
  subject_reference: string | null
  subject_title: string | null
  detected_automatically: boolean
  is_mandatory: boolean
  status: string
  due_date: string | null
  completed_at: string | null
  note: string | null
  completed_by_name: string | null
}

const props = defineProps<{
  rows: Row[]
  detail: {
    change: Record<string, any>
    impacts: Impact[]
    blockers: string[]
    impact_types: { key: string; label: string; note: string; mandatory: boolean }[]
  } | null
  categories: { key: string; label: string }[]
  natures: { key: string; label: string }[]
  riskRatings: string[]
  impactTypes: { key: string; label: string; note: string; mandatory: boolean }[]
  categoryImpacts: Record<string, string[]>
  statuses: string[]
  sites: { id: number; name: string }[]
  contractors: { id: number; name: string }[]
  people: Record<string, any>[]
  canRequest?: boolean
  canManage?: boolean
  noCompany?: boolean
}>()

const statusFilter = ref<string | null>(null)

watch(statusFilter, value => {
  router.get('/sheq/changes',
    { status: value ?? undefined, change: props.detail?.change.uuid ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
})

function open(row: Row) {
  router.get('/sheq/changes', { change: row.uuid, status: statusFilter.value ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

const label = (value: string | null) =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const statusColour = (status: string) => ({
  DRAFT: 'grey', SUBMITTED: 'info', APPROVED: 'success', IMPLEMENTED: 'teal',
  REVIEWED: 'success', REJECTED: 'error', CANCELLED: 'grey',
}[status] ?? 'grey')

const riskColour = (rating: string | null) => ({
  LOW: 'success', MEDIUM: 'info', HIGH: 'warning', CRITICAL: 'error',
}[rating ?? ''] ?? 'grey')

// ── Registering one (MOC-1) ───────────────────────────────────────────────────────────────────
const dialog = ref(false)

const form = useForm({
  title: '',
  description: '',
  change_category: 'EQUIPMENT',
  change_nature: 'PERMANENT',
  site_id: null as number | null,
  contractor_id: null as number | null,
  risk_rating: null as string | null,
  impact_summary: '',
  planned_date: '',
})

/*
 * What this kind of change usually touches, shown while they are still typing. Not a promise —
 * the detector lists candidates and a person answers them — but it is the moment at which
 * somebody remembers the emergency plan, which is worth more than the same list after the fact.
 */
const likelyImpacts = computed(() =>
  (props.categoryImpacts[form.change_category] ?? [])
    .map(key => props.impactTypes.find(t => t.key === key))
    .filter((t): t is NonNullable<typeof t> => !!t))

function submit() {
  form.post('/sheq/changes', {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

// ── Answering an impact (MOC-2, MOC-4) ────────────────────────────────────────────────────────
const deciding = ref<Impact | null>(null)

const decideForm = useForm({ status: 'DONE', note: '' })

function openDecide(impact: Impact, status: string) {
  deciding.value = impact
  decideForm.reset()
  decideForm.status = status
}

function submitDecide() {
  if (!deciding.value) return

  decideForm.post(`/sheq/changes/impacts/${deciding.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { deciding.value = null },
  })
}

function markDone(impact: Impact) {
  router.post(`/sheq/changes/impacts/${impact.uuid}`, { status: 'DONE' },
    { preserveScroll: true })
}

// ── The rest of the lifecycle ─────────────────────────────────────────────────────────────────
function act(path: string, data: Record<string, unknown> = {}) {
  if (!props.detail) return

  router.post(`/sheq/changes/${props.detail.change.uuid}/${path}`, data,
    { preserveScroll: true })
}

const reviewing = ref(false)
const reviewForm = useForm({ effectiveness_result: 'EFFECTIVE', effectiveness_notes: '' })

function submitReview() {
  if (!props.detail) return

  reviewForm.post(`/sheq/changes/${props.detail.change.uuid}/review`, {
    preserveScroll: true,
    onSuccess: () => { reviewing.value = false },
  })
}

const addingImpact = ref(false)
const impactForm = useForm({
  impact_type: 'SOP',
  subject_title: '',
  is_mandatory: true,
  due_date: '',
})

function submitImpact() {
  if (!props.detail) return

  impactForm.post(`/sheq/changes/${props.detail.change.uuid}/impacts`, {
    preserveScroll: true,
    onSuccess: () => { addingImpact.value = false; impactForm.reset() },
  })
}

const impactNote = (key: string) =>
  props.impactTypes.find(t => t.key === key)?.note ?? ''
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Management of change</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          What is changing, what it invalidates, and nothing implemented until that is handled
        </p>
      </div>
      <VBtn v-if="canRequest" color="primary" variant="flat" prepend-icon="mdi-plus"
            @click="dialog = true">
        Register a change
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VRow>
        <!-- ── The register ─────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Changes</VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <VSelect
                v-model="statusFilter"
                :items="statuses.map(s => ({ value: s, title: label(s) }))"
                density="compact" variant="outlined" hide-details clearable label="Status"
              />
            </VCardText>
            <VDivider />

            <div v-if="!rows.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-swap-horizontal-variant" size="32" class="mb-2" />
              <div class="text-body-2">No changes registered.</div>
            </div>

            <VTable v-else density="compact" class="moc-list">
              <tbody>
                <tr v-for="row in rows" :key="row.uuid" style="cursor: pointer"
                    :class="detail?.change.uuid === row.uuid ? 'bg-surface-variant' : ''"
                    @click="open(row)">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal" :color="statusColour(row.status)">
                        {{ label(row.status) }}
                      </VChip>
                      <span class="text-body-2 font-weight-medium">{{ row.reference }}</span>
                      <VChip v-if="row.risk_rating" size="x-small" variant="tonal"
                             :color="riskColour(row.risk_rating)">
                        {{ label(row.risk_rating) }}
                      </VChip>
                      <VChip v-if="row.change_nature === 'EMERGENCY'" size="x-small"
                             variant="tonal" color="error">
                        emergency
                      </VChip>
                    </div>
                    <div class="text-body-2">{{ row.title }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ label(row.change_category) }}
                      <template v-if="row.site_name"> · {{ row.site_name }}</template>
                      <template v-if="row.contractor_name"> · {{ row.contractor_name }}</template>
                    </div>
                    <div class="text-caption mt-1">
                      <!-- The blocking count, not just the total: four outstanding of which none
                           is mandatory is a different morning from one of which it is. -->
                      <span v-if="row.blocking_outstanding" class="text-error">
                        {{ row.blocking_outstanding }} mandatory outstanding
                      </span>
                      <span v-else-if="row.impacts_outstanding" class="text-medium-emphasis">
                        {{ row.impacts_outstanding }} outstanding, none blocking
                      </span>
                      <span v-else-if="row.impacts_total" class="text-success">
                        all {{ row.impacts_total }} handled
                      </span>
                      <span v-if="row.review_overdue" class="text-error">
                        · review overdue {{ row.effectiveness_review_due }}
                      </span>
                      <span v-else-if="row.effectiveness_result" class="text-medium-emphasis">
                        · {{ label(row.effectiveness_result) }}
                      </span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <!-- ── The open change ──────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard v-if="!detail" elevation="0" border rounded="lg">
            <div class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-gesture-tap" size="32" class="mb-2" />
              <div class="text-body-2">Pick a change to see what it affects.</div>
            </div>
          </VCard>

          <template v-else>
            <VCard elevation="0" border rounded="lg" class="mb-4">
              <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                {{ detail.change.reference }} — {{ detail.change.title }}
              </VCardTitle>
              <VDivider />

              <!-- ── MOC-4: why it cannot be implemented yet ────────────────────────────── -->
              <VAlert v-if="detail.blockers.length" type="warning" variant="tonal" rounded="0">
                <div class="font-weight-semibold mb-1">
                  Not ready to implement
                </div>
                <ul class="pl-5">
                  <li v-for="(blocker, i) in detail.blockers" :key="i" class="text-body-2">
                    {{ blocker }}
                  </li>
                </ul>
              </VAlert>
              <VAlert v-else-if="['DRAFT', 'SUBMITTED', 'APPROVED'].includes(detail.change.status)"
                      type="success" variant="tonal" rounded="0" density="compact">
                Everything required has been handled. This change can be implemented.
              </VAlert>

              <VCardText>
                <div class="text-body-2">{{ detail.change.description }}</div>
                <div v-if="detail.change.impact_summary" class="text-body-2 mt-2">
                  <strong>What it touches:</strong> {{ detail.change.impact_summary }}
                </div>
                <div class="text-caption text-medium-emphasis mt-2">
                  {{ label(detail.change.change_category) }}
                  · {{ label(detail.change.change_nature) }}
                  <template v-if="detail.change.risk_rating">
                    · {{ label(detail.change.risk_rating) }} risk
                  </template>
                  <template v-if="detail.change.planned_date">
                    · planned {{ detail.change.planned_date }}
                  </template>
                  <template v-if="detail.change.implemented_at">
                    · implemented {{ detail.change.implemented_at }}
                  </template>
                </div>
                <VAlert v-if="detail.change.rejection_reason" type="error" variant="tonal"
                        density="compact" class="mt-3">
                  Rejected: {{ detail.change.rejection_reason }}
                </VAlert>
              </VCardText>

              <VDivider />
              <VCardActions class="px-4 py-3 flex-wrap ga-2">
                <VBtn v-if="canRequest && detail.change.status === 'DRAFT'" color="primary"
                      variant="flat" size="small" @click="act('submit')">
                  Submit for approval
                </VBtn>
                <VBtn v-if="canManage && ['DRAFT', 'SUBMITTED', 'APPROVED']
                        .includes(detail.change.status)"
                      :disabled="detail.blockers.length > 0" color="teal" variant="flat"
                      size="small" @click="act('implement')">
                  Mark implemented
                </VBtn>
                <VBtn v-if="canManage && detail.change.status === 'IMPLEMENTED'" color="primary"
                      variant="flat" size="small" @click="reviewing = true">
                  Record the review
                </VBtn>
                <VSpacer />
                <VBtn v-if="canRequest" size="small" variant="text"
                      @click="addingImpact = true">
                  Add something it affects
                </VBtn>
              </VCardActions>

              <!-- ── MOC-5 ──────────────────────────────────────────────────────────────── -->
              <template v-if="detail.change.effectiveness_result">
                <VDivider />
                <VCardText>
                  <div class="text-subtitle-2 font-weight-bold">
                    Review: {{ label(detail.change.effectiveness_result) }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ detail.change.effectiveness_notes }}
                  </div>
                </VCardText>
              </template>
              <VAlert v-else-if="detail.change.effectiveness_review_due" type="info"
                      variant="tonal" rounded="0" density="compact">
                Effectiveness review due {{ detail.change.effectiveness_review_due }}.
              </VAlert>
            </VCard>

            <!-- ── MOC-2: what it affects ───────────────────────────────────────────────── -->
            <VCard elevation="0" border rounded="lg">
              <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                What this change affects
              </VCardTitle>
              <VDivider />
              <VCardText class="pb-2">
                <!--
                  Said in these words on purpose. This system does not know that a particular risk
                  assessment depends on a particular pump — no site keeps that graph — so what is
                  listed is what is in force here, not what is provably affected. Claiming
                  otherwise would have somebody signing off four procedures without reading them.
                -->
                <p class="text-body-2 text-medium-emphasis mb-0">
                  These are the records in force here that a change of this kind usually touches.
                  It is a list to go through, not a list of things known to be affected — mark
                  each one dealt with, or not applicable with a reason.
                </p>
              </VCardText>

              <div v-if="!detail.impacts.length" class="text-center py-8 text-medium-emphasis">
                <div class="text-body-2">Nothing was found to check.</div>
              </div>

              <VTable v-else density="compact">
                <tbody>
                  <tr v-for="impact in detail.impacts" :key="impact.uuid">
                    <td style="width: 34px">
                      <VIcon
                        :icon="impact.status === 'DONE' ? 'mdi-check-circle'
                          : impact.status === 'NOT_APPLICABLE' ? 'mdi-minus-circle-outline'
                          : 'mdi-circle-outline'"
                        :color="impact.status === 'DONE' ? 'success'
                          : impact.status === 'NOT_APPLICABLE' ? 'grey'
                          : (impact.is_mandatory ? 'error' : 'warning')"
                        size="18"
                      />
                    </td>
                    <td class="py-2">
                      <div class="d-flex align-center flex-wrap ga-2">
                        <VChip size="x-small" variant="tonal">
                          {{ impactTypes.find(t => t.key === impact.impact_type)?.label
                             ?? label(impact.impact_type) }}
                        </VChip>
                        <span class="text-body-2">
                          {{ impact.subject_reference ? impact.subject_reference + ' — ' : '' }}
                          {{ impact.subject_title }}
                        </span>
                        <VChip v-if="impact.is_mandatory" size="x-small" variant="text"
                               color="error">
                          blocks the change
                        </VChip>
                        <VChip v-if="!impact.detected_automatically" size="x-small" variant="text">
                          added by hand
                        </VChip>
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        {{ impactNote(impact.impact_type) }}
                      </div>
                      <div v-if="impact.note" class="text-caption mt-1">
                        {{ impact.status === 'NOT_APPLICABLE' ? 'Not applicable: ' : '' }}
                        {{ impact.note }}
                        <template v-if="impact.completed_by_name">
                          — {{ impact.completed_by_name }}
                        </template>
                      </div>
                    </td>
                    <td style="width: 160px" class="text-end">
                      <template v-if="impact.status === 'OUTSTANDING' && canRequest">
                        <VBtn size="x-small" variant="text" @click="markDone(impact)">
                          Done
                        </VBtn>
                        <VBtn size="x-small" variant="text"
                              @click="openDecide(impact, 'NOT_APPLICABLE')">
                          N/A
                        </VBtn>
                      </template>
                      <VBtn v-else-if="canRequest" size="x-small" variant="text"
                            @click="openDecide(impact, 'OUTSTANDING')">
                        Reopen
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCard>
          </template>
        </VCol>
      </VRow>
    </template>

    <!-- ── MOC-1: registering a change ───────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="780" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Register a change</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.title" density="comfortable" variant="outlined"
                          hide-details="auto" label="What is changing"
                          :error-messages="form.errors.title" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.change_category"
                       :items="categories.map(c => ({ value: c.key, title: c.label }))"
                       density="comfortable" variant="outlined" hide-details="auto" label="Kind"
                       :error-messages="form.errors.change_category" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.change_nature"
                       :items="natures.map(n => ({ value: n.key, title: n.label }))"
                       density="comfortable" variant="outlined" hide-details label="Nature" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" rows="3" density="comfortable"
                         variant="outlined" hide-details label="What it involves" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.risk_rating"
                       :items="riskRatings.map(r => ({ value: r, title: label(r) }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Risk"
                       hint="Decides who is authorised to approve it."
                       persistent-hint />
            </VCol>
            <VCol v-if="form.change_category === 'CONTRACTOR'" cols="12">
              <VSelect v-model="form.contractor_id"
                       :items="contractors.map(c => ({ value: c.id, title: c.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Contractor being changed" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.planned_date" type="date" density="comfortable"
                          variant="outlined" hide-details label="Planned for" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.impact_summary" rows="2" density="comfortable"
                         variant="outlined" hide-details
                         label="What you think it affects"
                         hint="In your own words. The list below is checked as well." 
                         persistent-hint />
            </VCol>

            <VCol v-if="likelyImpacts.length" cols="12">
              <VDivider class="mb-3" />
              <div class="text-subtitle-2 font-weight-bold">
                A change of this kind usually touches
              </div>
              <!-- Shown while they are still typing, which is the moment somebody remembers the
                   emergency plan — worth more than the same list after the fact. -->
              <div class="d-flex flex-wrap ga-1 mt-2">
                <VChip v-for="t in likelyImpacts" :key="t.key" size="small" variant="tonal">
                  {{ t.label }}
                </VChip>
              </div>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing"
                :disabled="!form.title.trim()" @click="submit">
            Register
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Dismissing or reopening an impact ─────────────────────────────────────────────── -->
    <VDialog :model-value="deciding !== null" max-width="560"
             @update:model-value="deciding = null">
      <VCard v-if="deciding">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ decideForm.status === 'NOT_APPLICABLE' ? 'Not applicable' : 'Reopen' }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 mb-3">
            {{ deciding.subject_reference ? deciding.subject_reference + ' — ' : ''
            }}{{ deciding.subject_title }}
          </p>
          <!-- Required when dismissing. A mandatory control cleared with one click and no
               explanation looks identical to one that was genuinely irrelevant, and that is the
               hole in this whole feature. -->
          <VTextarea v-model="decideForm.note" rows="3" density="comfortable" variant="outlined"
                     hide-details="auto"
                     :label="decideForm.status === 'NOT_APPLICABLE'
                       ? 'Why does this not apply?' : 'Why reopen it?'"
                     :error-messages="decideForm.errors.note || decideForm.errors.impact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="deciding = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="decideForm.processing"
                :disabled="decideForm.status === 'NOT_APPLICABLE' && !decideForm.note.trim()"
                @click="submitDecide">
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Adding an impact the detector could not know about ────────────────────────────── -->
    <VDialog v-model="addingImpact" max-width="600">
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Something else this change affects
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="impactForm.impact_type"
                       :items="impactTypes.map(t => ({ value: t.key, title: t.label }))"
                       density="comfortable" variant="outlined" hide-details label="Kind" />
              <p class="text-caption text-medium-emphasis mt-1 mb-0">
                {{ impactNote(impactForm.impact_type) }}
              </p>
            </VCol>
            <VCol cols="12">
              <VTextField v-model="impactForm.subject_title" density="comfortable"
                          variant="outlined" hide-details="auto" label="What it is"
                          :error-messages="impactForm.errors.subject_title" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="impactForm.due_date" type="date" density="comfortable"
                          variant="outlined" hide-details label="Due" />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="impactForm.is_mandatory" color="error" density="compact"
                       hide-details label="Must be done before the change" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="addingImpact = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="impactForm.processing"
                :disabled="!impactForm.subject_title.trim()" @click="submitImpact">
            Add
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── MOC-5: the review ─────────────────────────────────────────────────────────────── -->
    <VDialog v-model="reviewing" max-width="600">
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Did the change achieve what it was meant to?
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRadioGroup v-model="reviewForm.effectiveness_result" hide-details>
            <VRadio value="EFFECTIVE" label="Effective" />
            <VRadio value="PARTIALLY_EFFECTIVE" label="Partially effective" />
            <VRadio value="NOT_EFFECTIVE" label="Not effective" />
          </VRadioGroup>
          <!-- Required unless it worked. "Not effective" on its own leaves the next person
               unable to tell whether the change was wrong or merely unfinished. -->
          <VTextarea v-model="reviewForm.effectiveness_notes" rows="3" density="comfortable"
                     variant="outlined" hide-details="auto" class="mt-3"
                     label="What happened"
                     :error-messages="reviewForm.errors.effectiveness_notes
                       || reviewForm.errors.review" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="reviewing = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="reviewForm.processing"
                :disabled="reviewForm.effectiveness_result !== 'EFFECTIVE'
                  && !reviewForm.effectiveness_notes.trim()"
                @click="submitReview">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.moc-list { max-height: 740px; overflow-y: auto; }
</style>
