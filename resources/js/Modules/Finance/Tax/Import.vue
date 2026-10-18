<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'

const route = ziggyRoute

type Batch = { uuid: string; kind: string; source_system: string; file_name: string; status: string; rows: number; valid: number; errors: number
  total: string; uploaded_by: string | null; uploaded_by_id: number | null; signed_off_by: string | null; created_at: string | null; summary: Record<string, number> | null }

const props = defineProps<{
  batches: Batch[]
  open: { uuid: string; kind: string; status: string; headers: string[]; rows: Array<{ line: number; data: Record<string, string>; errors: string[] }>; total: string; uploaded_by_id: number | null } | null
  kinds: string[]
  can: { commit: boolean; upload: boolean }
  me: number
}>()

const form = useForm({ kind: 'registrations', source_system: '', file: null as File | null })
function upload() {
  form.post(route('tax.import.preview'), { forceFormData: true })
}

const decision = ref<'commit' | 'reject' | null>(null)
const decisionForm = useForm({ comment: '' })
function decide() {
  if (!props.open || !decision.value) return
  decisionForm.post(route(decision.value === 'commit' ? 'tax.import.commit' : 'tax.import.reject', props.open.uuid), {
    onSuccess: () => { decision.value = null },
  })
}
const help: Record<string, string> = {
  registrations: 'One row per tax a business is registered for. Active rows are confirmed by the sign-off.',
  liabilities: 'Opening balances owed to (or by) SARS, with anything already paid before the move.',
  returns: 'Historical returns. Without both a SARS reference and an evidence reference a return is imported as unverified.',
  carry_forwards: 'Assessed losses and allowance balances, with where each comes from.',
}
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Import historical tax data</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Upload a CSV in the template's layout. Every row is checked and the file is previewed with its errors and total; nothing is written until someone other than the uploader signs it off.
    </p>

    <VRow>
      <VCol cols="12" md="4">
        <VCard v-if="can.upload" border>
          <VCardItem><VCardTitle class="text-body-1">New import</VCardTitle></VCardItem>
          <VCardText>
            <VSelect v-model="form.kind" :items="kinds.map(k => ({ title: label(k), value: k }))" label="What is in the file" />
            <p class="text-caption text-medium-emphasis">{{ help[form.kind] }}
              <a :href="route('tax.import.template', form.kind)">Download the template</a>.</p>
            <VTextField v-model="form.source_system" label="Source system (e.g. Sage Pastel, spreadsheet)" :error-messages="form.errors.source_system" />
            <VFileInput label="CSV file" accept=".csv" density="compact" :error-messages="form.errors.file" @update:model-value="(f: any) => form.file = Array.isArray(f) ? f[0] : f" />
          </VCardText>
          <VCardActions><VSpacer /><VBtn color="primary" :loading="form.processing" :disabled="!form.file || !form.source_system" @click="upload">Preview</VBtn></VCardActions>
        </VCard>

        <VCard border :class="{ 'mt-4': can.upload }">
          <VCardItem><VCardTitle class="text-body-1">Imports</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="b in batches" :key="b.uuid" :active="open?.uuid === b.uuid" @click="router.get(route('tax.import.index', { batch: b.uuid }))">
              <VListItemTitle class="text-body-2">{{ label(b.kind) }} · {{ b.file_name }}</VListItemTitle>
              <VListItemSubtitle>{{ b.valid }}/{{ b.rows }} valid · {{ b.uploaded_by }} · {{ b.created_at }}</VListItemSubtitle>
              <template #append><VChip size="x-small" :color="b.status === 'committed' ? 'success' : b.status === 'rejected' ? 'grey' : 'warning'" label>{{ label(b.status) }}</VChip></template>
            </VListItem>
            <VListItem v-if="!batches.length" class="text-caption text-medium-emphasis">No imports yet.</VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard v-if="open" border>
          <VCardItem>
            <VCardTitle class="text-body-1">Preview · {{ label(open.kind) }}</VCardTitle>
            <VCardSubtitle>{{ open.rows.filter(r => !r.errors.length).length }} valid row(s), total {{ money(open.total) }}</VCardSubtitle>
            <template #append>
              <div v-if="open.status === 'previewed' && can.commit" class="d-flex gap-2">
                <VBtn variant="text" color="warning" @click="decision = 'reject'">Reject</VBtn>
                <VBtn color="success" variant="tonal" :disabled="open.uploaded_by_id === me" @click="decision = 'commit'">Sign off and import</VBtn>
              </div>
              <VChip v-else :color="statusColor[open.status]" label>{{ label(open.status) }}</VChip>
            </template>
          </VCardItem>
          <VAlert v-if="open.status === 'previewed' && open.uploaded_by_id === me" type="info" variant="tonal" density="compact" class="mx-4">You uploaded this file, so someone else signs off the import.</VAlert>
          <div style="overflow-x: auto">
            <VTable density="compact">
              <thead><tr><th>Row</th><th v-for="h in open.headers" :key="h" class="text-no-wrap">{{ h }}</th><th>Check</th></tr></thead>
              <tbody>
                <tr v-for="r in open.rows" :key="r.line" :class="r.errors.length ? 'bg-error-lighten' : ''">
                  <td>{{ r.line }}</td>
                  <td v-for="h in open.headers" :key="h" class="text-no-wrap">{{ r.data[h] ?? '' }}</td>
                  <td><span v-if="!r.errors.length" class="text-success">OK</span><span v-else class="text-error text-caption">{{ r.errors.join(' ') }}</span></td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
        <div v-else class="text-center text-medium-emphasis py-12">Upload a file, or open an earlier import, to see its rows.</div>
      </VCol>
    </VRow>

    <VDialog :model-value="decision !== null" max-width="520" @update:model-value="v => { if (!v) decision = null }">
      <VCard :title="decision === 'commit' ? 'Sign off and import' : 'Reject the import'">
        <VCardText>
          <p v-if="decision === 'commit'" class="text-body-2">The valid rows are written, each tagged with this import. Rows with errors are not; correct them and import them as a new file.</p>
          <VTextarea v-model="decisionForm.comment" :label="decision === 'commit' ? 'Basis for signing off (e.g. agreed to SARS statement of account dated …)' : 'Reason'" rows="3" auto-grow :error-messages="decisionForm.errors.comment" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="decision = null">Cancel</VBtn><VBtn :color="decision === 'commit' ? 'success' : 'warning'" :loading="decisionForm.processing" @click="decide">Confirm</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
