<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, statusColor } from '../Components/agri'

const props = defineProps<{
  documents: any[]
  filters: { farm: string | null; state: string; status: string }
  options: { farms: Array<{ id: string; name: string }>; types: string[]; subjects: string[] }
  can: { manage: boolean }
}>()
const dialog = ref(false)
const setState = (state: string) => router.get(route('agri.documents.index'), { farm: props.filters.farm || undefined, state }, { preserveState: false })
const download = (d: any) => { window.location.href = route('agri.documents.download', d.id) }
const setStatus = (d: any, status: string) => router.patch(route('agri.documents.update', d.id), { status }, { preserveScroll: true })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Compliance Documents</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Title deeds, water use licences, spray and operator certificates, brand registrations, permits and audits — with expiry reminders.</p>
      </div>
      <div class="d-flex gap-2">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-upload" @click="dialog = true">File a document</VBtn>
      </div>
    </div>

    <VCard>
      <VCardText class="d-flex gap-2 flex-wrap">
        <VChip v-for="s in ['all', 'expired', 'expiring', 'valid', 'none']" :key="s" :color="filters.state === s ? 'primary' : undefined" :variant="filters.state === s ? 'flat' : 'tonal'" size="small" @click="setState(s)">
          {{ s === 'none' ? 'No expiry' : human(s) }}
        </VChip>
      </VCardText>
      <VTable density="comfortable">
        <thead><tr><th>Document</th><th>Farm</th><th>Type</th><th>Expires</th><th>State</th><th /></tr></thead>
        <tbody>
          <tr v-for="d in documents" :key="d.id">
            <td><strong>{{ d.title }}</strong><div class="text-caption">{{ d.reference }}<template v-if="d.subject"> · {{ d.subject }}</template><template v-if="d.file_name"> · {{ d.file_name }}</template></div></td>
            <td>{{ d.farm }}</td>
            <td>{{ human(d.document_type) }}</td>
            <td>{{ d.expires_on ?? '—' }}<div v-if="d.days_left !== null" class="text-caption" :class="d.days_left < 0 ? 'text-error' : ''">{{ d.days_left < 0 ? `${-d.days_left} days ago` : `in ${d.days_left} days` }}</div></td>
            <td><VChip :color="statusColor(d.state)" size="small" variant="tonal">{{ d.state === 'none' ? 'No expiry' : human(d.state) }}</VChip></td>
            <td class="text-end"><RowMenu :actions="[
              { title: 'Download', icon: 'tabler-download', show: !!d.file_name, onClick: () => download(d) },
              { title: 'Mark superseded', icon: 'tabler-replace', show: can.manage, onClick: () => setStatus(d, 'superseded') },
              { title: 'Archive', icon: 'tabler-archive', show: can.manage, onClick: () => setStatus(d, 'archived') },
            ]" /></td>
          </tr>
          <tr v-if="!documents.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No documents here.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <FormDialog v-model="dialog" title="File a compliance document" :url="route('agri.documents.store')" submit-label="File"
      :values="{ farm_id: filters.farm ?? options.farms[0]?.id, remind_days: 30 }" :fields="[
        { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: options.farms.map(f => ({ title: f.name, value: f.id })) },
        { key: 'document_type', label: 'Type', type: 'select', required: true, items: options.types },
        { key: 'title', label: 'Title', required: true, cols: 8 }, { key: 'reference', label: 'Number / reference', cols: 4 },
        { key: 'subject_type', label: 'About a specific…', type: 'select', items: options.subjects },
        { key: 'subject_code', label: 'Its code / tag', show: (f: any) => !!f.subject_type },
        { key: 'issued_on', label: 'Issued', type: 'date', cols: 4 }, { key: 'expires_on', label: 'Expires', type: 'date', cols: 4 },
        { key: 'remind_days', label: 'Remind days before', type: 'number', cols: 4 },
        { key: 'file', label: 'File (PDF, image, Word)', type: 'file', cols: 12 },
      ]" />
  </div>
</template>
