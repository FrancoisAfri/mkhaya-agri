<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'
import RegisterTable from '@/Modules/Sheq/Partials/RegisterTable.vue'

const { t } = useI18n()

const props = defineProps<{rows: Record<string, any>[]; sites?: { id: number; name: string }[]; canManage?: boolean; noCompany?: boolean}>()

const siteItems = (props.sites ?? []).map(s => ({ title: s.name, value: s.id }))

/*
  How far through its audience each procedure has got.

  `requires_acknowledgement` used to be a flag the register displayed and could not act on: a
  company could publish a procedure, require everybody to confirm they had read it, and have no way
  to find out who had not. The outstanding count is the part somebody acts on, so it is the part
  that shows.
*/
const rows = computed(() => props.rows.map(row => ({
  ...row,
  ack_label: row.acknowledgement === null || row.acknowledgement === undefined
    ? '—'
    : row.acknowledgement.expected === 0
      ? t('sheq.registers.documents.ack_nobody')
      : t('sheq.registers.documents.ack_progress', {
          done: row.acknowledgement.acknowledged,
          expected: row.acknowledgement.expected,
        }),
  ack_outstanding: (row.acknowledgement?.outstanding ?? 0) > 0,
})))

/*
  Putting a version in force.

  Publishing had no control and no route, so every procedure authored here stayed a draft: the
  reader library shows published documents only and was therefore always empty, and nobody could
  be asked to acknowledge anything. A draft is a procedure nobody is following.
*/
const publishing = ref<Record<string, any> | null>(null)
const publishForm = useForm({ version: 1 })

function openPublish(row: Record<string, any>) {
  publishing.value = row
  // The draft that exists, as the version to put in force — named rather than assumed, because
  // publishing the wrong text is the one mistake this screen must not make easy.
  publishForm.version = Number(row.current_version) || 1
  publishForm.clearErrors()
}

function submitPublish() {
  if (!publishing.value) return

  publishForm.post(`/sheq/documents/${publishing.value.uuid}/publish`, {
    preserveScroll: true,
    onSuccess: () => { publishing.value = null },
  })
}

/** Drafts and nothing else: these are the procedures nobody is following yet. */
const unpublished = computed(() =>
  props.rows.filter(row => row.status === 'DRAFT' || row.status === 'IN_REVIEW'))

const statusColour = (status: string) => ({
  DRAFT: 'grey', OPEN: 'warning', APPROVED: 'success', ACTIVE: 'success', ISSUED: 'info',
  IN_PROGRESS: 'info', COMPLETED: 'success', CLOSED: 'grey', SUPERSEDED: 'grey',
  SUSPENDED: 'error', REJECTED: 'error', PUBLISHED: 'success', WITHDRAWN: 'error',
  VALID: 'success', EXPIRED: 'error', BLOCKED: 'error', COMPLIANT: 'success',
  EXPIRING: 'warning', PENDING: 'warning', TRIAGED: 'info',
}[status] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.documents.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.documents.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.registers.documents.add')" action="/sheq/documents"
        :button-label="t('sheq.registers.documents.add')" :note="t('sheq.registers.documents.add_note')"
        :fields="[
        { key: 'doc_number', label: t('sheq.registers.col.doc_number'), required: true },
        { key: 'title', label: t('sheq.registers.col.title'), required: true },
        { key: 'doc_type', label: t('sheq.registers.col.type'), type: 'select',
          items: [
            { title: 'SOP', value: 'SOP' },
            { title: 'Policy', value: 'POLICY' },
            { title: 'Work instruction', value: 'WORK_INSTRUCTION' },
            { title: 'Form', value: 'FORM' },
            { title: 'Register', value: 'REGISTER' },
          ] },
        { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select', items: siteItems },
        { key: 'review_due', label: t('sheq.registers.col.review_due'), type: 'date' },
        { key: 'requires_acknowledgement', label: t('sheq.registers.col.needs_ack'), type: 'switch' },
        { key: 'audience', label: t('sheq.registers.col.who_may_read'), type: 'select', items: [
            { title: t('sheq.registers.audience.everyone'), value: 'EVERYONE' },
            { title: t('sheq.registers.audience.site'), value: 'SITE' },
            { title: t('sheq.registers.audience.roles'), value: 'ROLES' },
          ], hint: t('sheq.registers.audience.hint') },
        { key: 'files', label: t('sheq.registers.col.attach'), type: 'files', cols: 12,
          hint: t('sheq.registers.attach_hint') },
      ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      :title="t('sheq.registers.documents.list')" :rows="rows"
      :search-keys="['doc_number', 'title', 'doc_type']"
      :empty-hint="t('sheq.registers.documents.empty')"
      :columns="[
        { key: 'doc_number', label: t('sheq.registers.col.doc_number'), width: '140px' },
        { key: 'title', label: t('sheq.registers.col.title') },
        { key: 'doc_type', label: t('sheq.registers.col.type'), kind: 'muted' },
        { key: 'current_version', label: t('sheq.registers.col.version'), kind: 'muted' },
        { key: 'status', label: t('sheq.registers.col.status'), kind: 'chip', chipColour: (r) => statusColour(r.status) },
        { key: 'review_due', label: t('sheq.registers.col.review_due'), kind: 'flag', flagField: 'review_overdue' },
        { key: 'ack_label', label: t('sheq.registers.col.acknowledged'), kind: 'flag', flagField: 'ack_outstanding' },
      ]"
    />

    <!--
      Which drafts are waiting to be issued. Said as its own list rather than left to be spotted in
      the register: a procedure that was written and never put in force is indistinguishable, in a
      status column, from one that is simply new.
    -->
    <VCard v-if="canManage && unpublished.length" elevation="0" border rounded="lg" class="mt-4">
      <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
        {{ t('sheq.registers.documents.awaiting_publish', { count: unpublished.length }) }}
      </VCardTitle>
      <VCardText class="pt-0 pb-2">
        <p class="text-body-2 text-medium-emphasis">
          {{ t('sheq.registers.documents.awaiting_publish_hint') }}
        </p>
      </VCardText>
      <VDivider />
      <VTable density="compact">
        <tbody>
          <tr v-for="row in unpublished" :key="row.uuid">
            <td class="text-body-2">
              <div class="font-weight-medium">{{ row.doc_number }}</div>
              <div class="text-caption text-medium-emphasis">{{ row.title }}</div>
            </td>
            <td style="width: 140px" class="text-end">
              <VBtn size="small" variant="tonal" color="primary" @click="openPublish(row)">
                {{ t('sheq.registers.documents.publish') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="publishing !== null" max-width="520"
             @update:model-value="publishing = null">
      <VCard v-if="publishing">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.registers.documents.publish') }} · {{ publishing.doc_number }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.registers.documents.publish_note') }}
          </p>
          <VTextField
            v-model.number="publishForm.version" type="number" min="1"
            :label="t('sheq.registers.documents.version_to_publish')"
            variant="outlined" density="comfortable"
            :error-messages="publishForm.errors.version"
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="publishing = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="publishForm.processing"
                :disabled="publishForm.processing" @click="submitPublish">
            {{ t('sheq.registers.documents.publish') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
