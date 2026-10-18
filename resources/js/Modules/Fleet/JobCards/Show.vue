<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  card: any
  history: any[]
  mechanics: { id: number; name: string }[]
  serviceTypes: { id: number; name: string; standard_hours: number | null; standard_cost: number | null }[]
  catalogParts: { id: number; category_id: number | null; name: string; part_number: string | null; unit: string | null; unit_price: number }[]
  partCategories: { id: number; name: string }[]
  canEdit: boolean
}>()

const tab = ref('details')

const money = (n: any) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusLabel = (s: string) => t(`fleet_job_cards.status.${s}`)

function statusColor(s: string): string {
  switch (s) {
    case 'draft': return 'default'
    case 'pending_approval': return 'warning'
    case 'approved': return 'info'
    case 'in_progress': return 'primary'
    case 'awaiting_feedback': return 'orange'
    case 'completed': case 'closed': return 'success'
    default: return 'error'
  }
}

/** Which lifecycle buttons make sense from the card's current status. */
const canSubmit = computed(() => props.card.status === 'draft')
const canStart  = computed(() => props.card.status === 'approved')
const canFinish = computed(() => ['in_progress', 'awaiting_feedback'].includes(props.card.status))
const canClose  = computed(() => props.card.status === 'completed')
const canCancel = computed(() => !['closed', 'cancelled', 'rejected'].includes(props.card.status))

function post(action: string, data: Record<string, any> = {}) {
  router.post(route(`fleet.job-cards.${action}`, props.card.uuid), data, { preserveScroll: true })
}

// ── Parts ────────────────────────────────────────────────────────────────────
const partForm = useForm({ catalog_part_id: null as number | null, name: '', part_number: '', quantity: 1, unit_price: 0 })

function pickCatalogPart(id: number | null) {
  const part = props.catalogParts.find(p => p.id === id)
  if (part) {
    partForm.name = part.name
    partForm.part_number = part.part_number ?? ''
    partForm.unit_price = part.unit_price
  }
}

function addPart() {
  partForm.post(route('fleet.job-cards.parts.store', props.card.uuid), {
    preserveScroll: true,
    onSuccess: () => partForm.reset(),
  })
}

function removePart(partId: number) {
  router.delete(route('fleet.job-cards.parts.destroy', [props.card.uuid, partId]), { preserveScroll: true })
}

// ── Mechanic feedback ────────────────────────────────────────────────────────
const feedbackForm = useForm({ mechanic_feedback: '', work_performed: '', labour_hours: props.card.labour_hours ?? 0 })

function submitFeedback() {
  feedbackForm.post(route('fleet.job-cards.mechanic-feedback', props.card.uuid), { preserveScroll: true })
}

// ── Notes ────────────────────────────────────────────────────────────────────
const noteForm = useForm({ body: '' })

function addNote() {
  noteForm.post(route('fleet.job-cards.notes.store', props.card.uuid), {
    preserveScroll: true,
    onSuccess: () => noteForm.reset(),
  })
}

// ── Cancel ───────────────────────────────────────────────────────────────────
const cancelDialog = ref(false)
const cancelForm = useForm({ reason: '' })

function submitCancel() {
  cancelForm.post(route('fleet.job-cards.cancel', props.card.uuid), {
    preserveScroll: true,
    onSuccess: () => { cancelDialog.value = false; cancelForm.reset() },
  })
}

// ── Attachments ──────────────────────────────────────────────────────────────
const imageForm = useForm({ file: null as File | null, caption: '' })
const docForm   = useForm({ file: null as File | null, title: '', document_type: '' })

function uploadImage() {
  imageForm.post(route('fleet.job-cards.images.store', props.card.uuid), {
    preserveScroll: true, forceFormData: true, onSuccess: () => imageForm.reset(),
  })
}

function uploadDocument() {
  docForm.post(route('fleet.job-cards.documents.store', props.card.uuid), {
    preserveScroll: true, forceFormData: true, onSuccess: () => docForm.reset(),
  })
}
</script>

<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-3">
          <h1 class="text-h5 font-weight-bold">{{ card.card_number }}</h1>
          <VChip :color="statusColor(card.status)" variant="tonal" size="small">{{ statusLabel(card.status) }}</VChip>
        </div>
        <p class="text-body-2 text-medium-emphasis mt-1">
          {{ card.vehicle?.registration_number }} — {{ card.vehicle?.make }} {{ card.vehicle?.model }}
        </p>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="canSubmit" color="primary" prepend-icon="tabler-send" @click="post('submit')">
          {{ t('fleet_job_cards.submit_for_approval') }}
        </VBtn>
        <VBtn v-if="canStart" color="primary" prepend-icon="tabler-player-play" @click="post('start')">
          {{ t('fleet_job_cards.start_work') }}
        </VBtn>
        <VBtn v-if="canClose" color="success" prepend-icon="tabler-lock" @click="post('close')">
          {{ t('fleet_job_cards.close_card') }}
        </VBtn>
        <VBtn v-if="canCancel" color="error" variant="tonal" prepend-icon="tabler-x" @click="cancelDialog = true">
          {{ t('fleet_job_cards.cancel_card') }}
        </VBtn>
        <VBtn variant="text" prepend-icon="tabler-printer" :href="route('fleet.job-cards.print', card.uuid)">
          {{ t('fleet_job_cards.print') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- Cost summary -->
      <VCol cols="12" md="3">
        <VCard class="mb-4">
          <VCardText>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2 text-medium-emphasis">{{ t('fleet_job_cards.parts_cost') }}</span>
              <span>{{ money(card.parts_cost) }}</span>
            </div>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2 text-medium-emphasis">{{ t('fleet_job_cards.labour_cost') }}</span>
              <span>{{ money(card.labour_cost) }}</span>
            </div>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2 text-medium-emphasis">{{ t('fleet_job_cards.other_cost') }}</span>
              <span>{{ money(card.other_cost) }}</span>
            </div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between font-weight-bold">
              <span>{{ t('fleet_job_cards.total_cost') }}</span>
              <span>{{ money(card.total_cost) }}</span>
            </div>
          </VCardText>
        </VCard>

        <!-- Approval chain -->
        <VCard v-if="card.approvals?.length">
          <VCardTitle class="text-subtitle-2">{{ t('fleet_job_cards.approval_chain') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="a in card.approvals" :key="a.id">
              <template #prepend>
                <VIcon
                  :icon="a.status === 'approved' ? 'tabler-circle-check' : a.status === 'rejected' ? 'tabler-circle-x' : 'tabler-circle-dashed'"
                  :color="a.status === 'approved' ? 'success' : a.status === 'rejected' ? 'error' : 'default'"
                  size="small"
                />
              </template>
              <VListItemTitle class="text-body-2">{{ a.step_name }}</VListItemTitle>
              <VListItemSubtitle v-if="a.comment" class="text-caption">{{ a.comment }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Main -->
      <VCol cols="12" md="9">
        <VCard>
          <VTabs v-model="tab" color="primary">
            <VTab value="details">{{ t('fleet_job_cards.tab_details') }}</VTab>
            <VTab value="parts">{{ t('fleet_job_cards.tab_parts') }} ({{ card.parts?.length ?? 0 }})</VTab>
            <VTab value="feedback">{{ t('fleet_job_cards.tab_feedback') }}</VTab>
            <VTab value="notes">{{ t('fleet_job_cards.tab_notes') }} ({{ card.notes?.length ?? 0 }})</VTab>
            <VTab value="files">{{ t('fleet_job_cards.tab_files') }}</VTab>
            <VTab value="history">{{ t('fleet_job_cards.tab_history') }}</VTab>
          </VTabs>
          <VDivider />

          <VTabsWindow v-model="tab">
            <!-- Details -->
            <VTabsWindowItem value="details">
              <VCardText>
                <VRow>
                  <VCol cols="12" md="6">
                    <div class="text-caption text-medium-emphasis">{{ t('fleet_job_cards.fault_description') }}</div>
                    <p class="text-body-2 mb-4">{{ card.fault_description || '—' }}</p>
                    <div class="text-caption text-medium-emphasis">{{ t('fleet_job_cards.work_requested') }}</div>
                    <p class="text-body-2 mb-4">{{ card.work_requested || '—' }}</p>
                    <div class="text-caption text-medium-emphasis">{{ t('fleet_job_cards.work_performed') }}</div>
                    <p class="text-body-2">{{ card.work_performed || '—' }}</p>
                  </VCol>
                  <VCol cols="12" md="6">
                    <VTable density="compact">
                      <tbody>
                        <tr><td class="text-medium-emphasis">{{ t('fleet_job_cards.priority_label') }}</td><td>{{ t(`fleet_job_cards.priority.${card.priority}`) }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('fleet_job_cards.odometer') }}</td><td>{{ card.odometer ?? '—' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('fleet_job_cards.opened') }}</td><td>{{ card.opened_date ?? '—' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('fleet_job_cards.due') }}</td><td>{{ card.due_date ?? '—' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('fleet_job_cards.labour_hours') }}</td><td>{{ card.labour_hours ?? 0 }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('fleet_job_cards.invoice_number') }}</td><td>{{ card.invoice_number ?? '—' }}</td></tr>
                      </tbody>
                    </VTable>
                  </VCol>
                </VRow>

                <VAlert v-if="card.cancellation_reason" type="error" variant="tonal" class="mt-4">
                  <strong>{{ t('fleet_job_cards.cancelled') }}:</strong> {{ card.cancellation_reason }}
                </VAlert>
              </VCardText>
            </VTabsWindowItem>

            <!-- Parts -->
            <VTabsWindowItem value="parts">
              <VCardText>
                <VTable density="compact" class="mb-4">
                  <thead>
                    <tr>
                      <th>{{ t('fleet_job_cards.part') }}</th>
                      <th>{{ t('fleet_job_cards.part_number') }}</th>
                      <th class="text-end">{{ t('fleet_job_cards.qty') }}</th>
                      <th class="text-end">{{ t('fleet_job_cards.unit_price') }}</th>
                      <th class="text-end">{{ t('fleet_job_cards.line_total') }}</th>
                      <th v-if="canEdit"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="p in card.parts" :key="p.id">
                      <td>{{ p.name }}</td>
                      <td>{{ p.part_number ?? '—' }}</td>
                      <td class="text-end">{{ p.quantity }}</td>
                      <td class="text-end">{{ money(p.unit_price) }}</td>
                      <td class="text-end">{{ money(p.line_total) }}</td>
                      <td v-if="canEdit" class="text-end">
                        <VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="removePart(p.id)" />
                      </td>
                    </tr>
                    <tr v-if="!card.parts?.length">
                      <td :colspan="canEdit ? 6 : 5" class="text-center text-medium-emphasis py-4">
                        {{ t('fleet_job_cards.no_parts') }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>

                <VForm v-if="canEdit" @submit.prevent="addPart">
                  <VRow dense align="center">
                    <VCol cols="12" md="4">
                      <VSelect
                        v-model="partForm.catalog_part_id"
                        :items="props.catalogParts"
                        item-title="name"
                        item-value="id"
                        :label="t('fleet_job_cards.from_catalogue')"
                        density="compact"
                        clearable
                        @update:model-value="pickCatalogPart"
                      />
                    </VCol>
                    <VCol cols="12" md="3">
                      <VTextField v-model="partForm.name" :label="t('fleet_job_cards.part')" density="compact" />
                    </VCol>
                    <VCol cols="6" md="2">
                      <VTextField v-model.number="partForm.quantity" type="number" step="0.01" :label="t('fleet_job_cards.qty')" density="compact" />
                    </VCol>
                    <VCol cols="6" md="2">
                      <VTextField v-model.number="partForm.unit_price" type="number" step="0.01" :label="t('fleet_job_cards.unit_price')" density="compact" />
                    </VCol>
                    <VCol cols="12" md="1">
                      <VBtn type="submit" color="primary" :loading="partForm.processing" block>{{ t('fleet_job_cards.add') }}</VBtn>
                    </VCol>
                  </VRow>
                </VForm>
              </VCardText>
            </VTabsWindowItem>

            <!-- Mechanic feedback -->
            <VTabsWindowItem value="feedback">
              <VCardText>
                <div v-if="card.mechanic_feedback" class="mb-4">
                  <div class="text-caption text-medium-emphasis">{{ t('fleet_job_cards.recorded_feedback') }}</div>
                  <p class="text-body-2">{{ card.mechanic_feedback }}</p>
                </div>

                <VForm v-if="canFinish" @submit.prevent="submitFeedback">
                  <VTextarea v-model="feedbackForm.mechanic_feedback" :label="t('fleet_job_cards.feedback_label')" rows="3" density="compact" class="mb-3" />
                  <VTextarea v-model="feedbackForm.work_performed" :label="t('fleet_job_cards.work_performed')" rows="2" density="compact" class="mb-3" />
                  <VTextField v-model.number="feedbackForm.labour_hours" type="number" step="0.25" :label="t('fleet_job_cards.labour_hours')" density="compact" class="mb-3" style="max-width: 220px;" />
                  <VBtn type="submit" color="primary" :loading="feedbackForm.processing">
                    {{ t('fleet_job_cards.record_feedback') }}
                  </VBtn>
                </VForm>
                <VAlert v-else-if="!card.mechanic_feedback" type="info" variant="tonal">
                  {{ t('fleet_job_cards.feedback_when_in_progress') }}
                </VAlert>
              </VCardText>
            </VTabsWindowItem>

            <!-- Notes -->
            <VTabsWindowItem value="notes">
              <VCardText>
                <VForm class="mb-4 d-flex gap-2" @submit.prevent="addNote">
                  <VTextField v-model="noteForm.body" :label="t('fleet_job_cards.add_note')" density="compact" hide-details />
                  <VBtn type="submit" color="primary" :loading="noteForm.processing">{{ t('fleet_job_cards.add') }}</VBtn>
                </VForm>

                <VList density="compact">
                  <VListItem v-for="n in card.notes" :key="n.id">
                    <VListItemTitle class="text-body-2">{{ n.body }}</VListItemTitle>
                    <VListItemSubtitle class="text-caption">
                      <VChip size="x-small" variant="tonal" class="me-2">{{ t(`fleet_job_cards.note_type.${n.note_type}`) }}</VChip>
                      {{ new Date(n.created_at).toLocaleString() }}
                    </VListItemSubtitle>
                  </VListItem>
                  <VListItem v-if="!card.notes?.length">
                    <VListItemTitle class="text-medium-emphasis text-center">{{ t('fleet_job_cards.no_notes') }}</VListItemTitle>
                  </VListItem>
                </VList>
              </VCardText>
            </VTabsWindowItem>

            <!-- Files -->
            <VTabsWindowItem value="files">
              <VCardText>
                <VRow>
                  <VCol cols="12" md="6">
                    <h3 class="text-subtitle-2 mb-2">{{ t('fleet_job_cards.images') }}</h3>
                    <VForm class="mb-3" @submit.prevent="uploadImage">
                      <VFileInput
                        :label="t('fleet_job_cards.choose_image')"
                        density="compact"
                        accept="image/*"
                        @update:model-value="(f: any) => imageForm.file = Array.isArray(f) ? f[0] : f"
                      />
                      <VTextField v-model="imageForm.caption" :label="t('fleet_job_cards.caption')" density="compact" class="mb-2" />
                      <VBtn type="submit" size="small" color="primary" :loading="imageForm.processing">{{ t('fleet_job_cards.upload') }}</VBtn>
                    </VForm>
                    <VList density="compact">
                      <VListItem v-for="img in card.images" :key="img.uuid">
                        <VListItemTitle class="text-body-2">{{ img.caption || img.file_name }}</VListItemTitle>
                        <template #append>
                          <VBtn size="x-small" variant="text" :href="route('fleet.job-cards.images.download', [card.uuid, img.uuid])">
                            {{ t('fleet_job_cards.download') }}
                          </VBtn>
                        </template>
                      </VListItem>
                    </VList>
                  </VCol>

                  <VCol cols="12" md="6">
                    <h3 class="text-subtitle-2 mb-2">{{ t('fleet_job_cards.documents') }}</h3>
                    <VForm class="mb-3" @submit.prevent="uploadDocument">
                      <VFileInput
                        :label="t('fleet_job_cards.choose_document')"
                        density="compact"
                        @update:model-value="(f: any) => docForm.file = Array.isArray(f) ? f[0] : f"
                      />
                      <VTextField v-model="docForm.title" :label="t('fleet_job_cards.doc_title')" density="compact" class="mb-2" />
                      <VBtn type="submit" size="small" color="primary" :loading="docForm.processing">{{ t('fleet_job_cards.upload') }}</VBtn>
                    </VForm>
                    <VList density="compact">
                      <VListItem v-for="d in card.documents" :key="d.uuid">
                        <VListItemTitle class="text-body-2">{{ d.title || d.file_name }}</VListItemTitle>
                        <template #append>
                          <VBtn size="x-small" variant="text" :href="route('fleet.job-cards.documents.download', [card.uuid, d.uuid])">
                            {{ t('fleet_job_cards.download') }}
                          </VBtn>
                        </template>
                      </VListItem>
                    </VList>
                  </VCol>
                </VRow>
              </VCardText>
            </VTabsWindowItem>

            <!-- History -->
            <VTabsWindowItem value="history">
              <VCardText>
                <VTimeline side="end" density="compact" truncate-line="both">
                  <VTimelineItem v-for="h in props.history" :key="h.id" size="x-small" dot-color="primary">
                    <div class="text-body-2 font-weight-medium">{{ t(`fleet_job_cards.action.${h.action}`) }}</div>
                    <div v-if="h.description" class="text-caption">{{ h.description }}</div>
                    <div class="text-caption text-medium-emphasis">{{ new Date(h.performed_at).toLocaleString() }}</div>
                  </VTimelineItem>
                </VTimeline>
              </VCardText>
            </VTabsWindowItem>
          </VTabsWindow>
        </VCard>
      </VCol>
    </VRow>

    <!-- Cancel dialog -->
    <VDialog v-model="cancelDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('fleet_job_cards.cancel_card') }}</VCardTitle>
        <VCardText>
          <VTextarea v-model="cancelForm.reason" :label="t('fleet_job_cards.cancel_reason')" rows="3" density="compact" :error-messages="cancelForm.errors.reason" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="cancelDialog = false">{{ t('fleet_job_cards.back') }}</VBtn>
          <VBtn color="error" :loading="cancelForm.processing" @click="submitCancel">{{ t('fleet_job_cards.confirm_cancel') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
