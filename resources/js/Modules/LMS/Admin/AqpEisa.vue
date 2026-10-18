<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Aqp = {
  id: number; name: string; acronym: string
  seta: { acronym: string } | null; seta_id: number | null
  registration_number: string | null
  website: string | null; contact_email: string | null; contact_phone: string | null
  is_active: boolean; notes: string | null
  learnerships_count: number; eisa_schedules_count: number
}

type EisaSchedule = {
  id: number; uuid: string
  learnership: { id: number; title: string } | null
  aqp: { acronym: string } | null
  hr_person: { id: number; first_name: string; surname: string; employee_number: string | null } | null
  scheduled_date: string; venue: string | null
  status: string; score: number | null; result: string | null
  certificate_number: string | null; certificate_issued_at: string | null
  aqp_reference: string | null; notes: string | null
}

const props = defineProps<{
  aqps: Aqp[]
  setas: { id: number; name: string; acronym: string }[]
  learnerships: { id: number; title: string; aqp_id: number | null; saqa_id: string | null; nqf_level: number | null }[]
  employees: { id: number; first_name: string; surname: string; employee_number: string | null }[]
  eisaSchedules: EisaSchedule[]
}>()

const confirmDialog = ref()

// ── AQP ───────────────────────────────────────────────────────────────────────
const aqpDialog  = ref(false)
const editingAqp = ref<Aqp | null>(null)

const aqpForm = useForm({
  name: '', acronym: '', seta_id: null as number | null,
  registration_number: '', website: '', contact_email: '', contact_phone: '',
  is_active: true, notes: '',
})

function openNewAqp() { editingAqp.value = null; aqpForm.reset(); aqpForm.is_active = true; aqpDialog.value = true }
function openEditAqp(a: Aqp) {
  editingAqp.value = a
  Object.assign(aqpForm, {
    name: a.name, acronym: a.acronym, seta_id: a.seta_id,
    registration_number: a.registration_number ?? '',
    website: a.website ?? '', contact_email: a.contact_email ?? '',
    contact_phone: a.contact_phone ?? '', is_active: a.is_active,
    notes: a.notes ?? '',
  })
  aqpDialog.value = true
}

function saveAqp() {
  if (editingAqp.value) {
    aqpForm.patch(route('lms.admin.qcto.aqp.update', { aqp: editingAqp.value.id }), { onSuccess: () => { aqpDialog.value = false } })
  } else {
    aqpForm.post(route('lms.admin.qcto.aqp.store'), { onSuccess: () => { aqpDialog.value = false } })
  }
}

function destroyAqp(a: Aqp) {
  confirmDialog.value.open(
    { title: t('lms_aqp_eisa.delete_title'), message: t('lms_aqp_eisa.delete_message', { name: a.name }), confirmText: t('lms_aqp_eisa.delete_button'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { useForm({}).delete(route('lms.admin.qcto.aqp.destroy', { aqp: a.id })) },
  )
}

// ── EISA ──────────────────────────────────────────────────────────────────────
const eisaDialog  = ref(false)
const editingEisa = ref<EisaSchedule | null>(null)

const eisaCreateForm = useForm({
  learnership_id: null as number | null,
  aqp_id: null as number | null,
  hr_person_id: null as number | null,
  scheduled_date: '', venue: '', aqp_reference: '', notes: '',
})

const eisaEditForm = useForm({
  scheduled_date: '', venue: '',
  status: 'scheduled',
  score: null as number | null,
  result: null as string | null,
  certificate_number: '', certificate_issued_at: '',
  aqp_reference: '', notes: '',
})

function openNewEisa() { editingEisa.value = null; eisaCreateForm.reset(); eisaDialog.value = true }
function openEditEisa(e: EisaSchedule) {
  editingEisa.value = e
  Object.assign(eisaEditForm, {
    scheduled_date: e.scheduled_date, venue: e.venue ?? '',
    status: e.status, score: e.score,
    result: e.result ?? null,
    certificate_number: e.certificate_number ?? '',
    certificate_issued_at: e.certificate_issued_at ?? '',
    aqp_reference: e.aqp_reference ?? '', notes: e.notes ?? '',
  })
  eisaDialog.value = true
}

function saveEisa() {
  if (editingEisa.value) {
    eisaEditForm.patch(route('lms.admin.qcto.eisa.update', { eisa: editingEisa.value.id }), { onSuccess: () => { eisaDialog.value = false } })
  } else {
    eisaCreateForm.post(route('lms.admin.qcto.eisa.store'), { onSuccess: () => { eisaDialog.value = false } })
  }
}

function destroyEisa(e: EisaSchedule) {
  confirmDialog.value.open(
    { title: t('lms_aqp_eisa.delete_eisa_title'), message: t('lms_aqp_eisa.delete_eisa_message'), confirmText: t('lms_aqp_eisa.delete_eisa_button'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { useForm({}).delete(route('lms.admin.qcto.eisa.destroy', { eisa: e.id })) },
  )
}

const statusColor: Record<string, string> = {
  scheduled: 'info', completed: 'success', absent: 'warning', deferred: 'default', cancelled: 'error',
}
const resultColor: Record<string, string> = {
  competent: 'success', not_yet_competent: 'error', absent: 'warning',
}
const tab = ref('aqps')
</script>

<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_aqp_eisa.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ t('lms_aqp_eisa.subtitle') }}
        </p>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="aqps">
        <VIcon icon="tabler-building" class="me-2" size="18" />
        {{ t('lms_aqp_eisa.tabs.aqps') }} ({{ aqps.length }})
      </VTab>
      <VTab value="eisa">
        <VIcon icon="tabler-clipboard-check" class="me-2" size="18" />
        {{ t('lms_aqp_eisa.tabs.eisa') }} ({{ eisaSchedules.length }})
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">
      <!-- ── AQPs tab ── -->
      <VTabsWindowItem value="aqps">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewAqp">{{ t('lms_aqp_eisa.add_aqp') }}</VBtn>
        </div>

        <VRow>
          <VCol v-for="a in aqps" :key="a.id" cols="12" md="6" lg="4">
            <VCard rounded="lg" elevation="0" border>
              <VCardText class="pa-4">
                <div class="d-flex align-start gap-3 mb-3">
                  <VAvatar color="primary" variant="tonal" size="42" rounded>
                    <VIcon icon="tabler-building" size="22" />
                  </VAvatar>
                  <div class="flex-1-1 min-width-0">
                    <p class="text-body-1 font-weight-semibold mb-0">{{ a.name }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">
                      {{ a.acronym }}
                      <span v-if="a.seta"> · {{ a.seta.acronym }}</span>
                    </p>
                  </div>
                  <VChip :color="a.is_active ? 'success' : 'default'" size="x-small" variant="tonal">
                    {{ a.is_active ? t('lms_aqp_eisa.active') : t('lms_aqp_eisa.inactive') }}
                  </VChip>
                </div>
                <div class="d-flex flex-column gap-1 mb-3 text-caption text-medium-emphasis">
                  <span v-if="a.registration_number">{{ t('lms_aqp_eisa.registration') }}: {{ a.registration_number }}</span>
                  <span v-if="a.contact_email">{{ a.contact_email }}</span>
                  <span v-if="a.contact_phone">{{ a.contact_phone }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <div class="d-flex gap-2">
                    <VChip size="x-small" variant="outlined">{{ a.learnerships_count }} {{ t('lms_aqp_eisa.learnerships') }}</VChip>
                    <VChip size="x-small" variant="outlined">{{ a.eisa_schedules_count }} {{ t('lms_aqp_eisa.eisas_count') }}</VChip>
                  </div>
                  <div class="d-flex gap-1">
                    <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEditAqp(a)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroyAqp(a)" />
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol v-if="!aqps.length" cols="12">
            <VCard rounded="lg" elevation="0" border>
              <VCardText class="text-center pa-8 text-medium-emphasis">
                {{ t('lms_aqp_eisa.empty_aqp') }}
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VTabsWindowItem>

      <!-- ── EISA tab ── -->
      <VTabsWindowItem value="eisa">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewEisa">{{ t('lms_aqp_eisa.schedule_eisa') }}</VBtn>
        </div>

        <VCard rounded="lg" elevation="0" border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('lms_aqp_eisa.table.learner') }}</th>
                <th>{{ t('lms_aqp_eisa.table.learnership') }}</th>
                <th>{{ t('lms_aqp_eisa.table.aqp') }}</th>
                <th>{{ t('lms_aqp_eisa.table.date') }}</th>
                <th>{{ t('lms_aqp_eisa.table.status') }}</th>
                <th>{{ t('lms_aqp_eisa.table.result') }}</th>
                <th>{{ t('lms_aqp_eisa.table.score') }}</th>
                <th>{{ t('lms_aqp_eisa.table.certificate') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in eisaSchedules" :key="e.id">
                <td>
                  <p class="text-body-2 font-weight-medium mb-0">
                    {{ e.hr_person ? e.hr_person.first_name + ' ' + e.hr_person.surname : '—' }}
                  </p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ e.hr_person?.employee_number }}</p>
                </td>
                <td class="text-caption">{{ e.learnership?.title ?? '—' }}</td>
                <td>
                  <VChip color="primary" size="x-small" variant="tonal">{{ e.aqp?.acronym ?? '—' }}</VChip>
                </td>
                <td class="text-caption">{{ e.scheduled_date }}</td>
                <td>
                  <VChip :color="statusColor[e.status]" size="x-small" variant="tonal" class="text-capitalize">
                    {{ e.status }}
                  </VChip>
                </td>
                <td>
                  <VChip v-if="e.result" :color="resultColor[e.result]" size="x-small" variant="tonal">
                    {{ e.result === 'competent' ? t('lms_aqp_eisa.competent') : e.result === 'not_yet_competent' ? t('lms_aqp_eisa.not_yet_competent') : t('lms_aqp_eisa.absent') }}
                  </VChip>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>{{ e.score !== null ? e.score + '%' : '—' }}</td>
                <td class="text-caption">{{ e.certificate_number ?? '—' }}</td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEditEisa(e)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroyEisa(e)" />
                  </div>
                </td>
              </tr>
              <tr v-if="!eisaSchedules.length">
                <td colspan="9" class="text-center text-medium-emphasis pa-6">{{ t('lms_aqp_eisa.empty_eisa') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- AQP Dialog -->
    <VDialog v-model="aqpDialog" max-width="540">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ editingAqp ? t('lms_aqp_eisa.dialog.edit_aqp') : t('lms_aqp_eisa.dialog.add_aqp') }}</VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="8">
              <VTextField v-model="aqpForm.name" :label="t('lms_aqp_eisa.dialog.full_name') + ' *'" :error-messages="aqpForm.errors.name" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model="aqpForm.acronym" :label="t('lms_aqp_eisa.dialog.acronym') + ' *'" :error-messages="aqpForm.errors.acronym" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="aqpForm.seta_id" :label="t('lms_aqp_eisa.dialog.seta')"
                :items="setas.map(s => ({title: s.acronym + ' — ' + s.name, value: s.id}))" clearable
                :error-messages="aqpForm.errors.seta_id" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="aqpForm.registration_number" :label="t('lms_aqp_eisa.dialog.registration_no')"
                :error-messages="aqpForm.errors.registration_number" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="aqpForm.website" :label="t('lms_aqp_eisa.dialog.website')"
                hint="e.g. www.aqp.co.za" persistent-hint
                :error-messages="aqpForm.errors.website" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="aqpForm.contact_email" :label="t('lms_aqp_eisa.dialog.contact_email')"
                :error-messages="aqpForm.errors.contact_email" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="aqpForm.contact_phone" :label="t('lms_aqp_eisa.dialog.contact_phone')"
                :error-messages="aqpForm.errors.contact_phone" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="aqpForm.notes" :label="t('lms_aqp_eisa.dialog.notes')" rows="2" auto-grow
                :error-messages="aqpForm.errors.notes" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="aqpForm.is_active" :label="t('lms_aqp_eisa.active')" color="success" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="aqpDialog = false">{{ t('lms_aqp_eisa.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="aqpForm.processing" @click="saveAqp">{{ t('lms_aqp_eisa.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- EISA Dialog -->
    <VDialog v-model="eisaDialog" max-width="560">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ editingEisa ? t('lms_aqp_eisa.dialog.update_eisa') : t('lms_aqp_eisa.dialog.schedule_eisa_title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <template v-if="!editingEisa">
              <VCol cols="12">
                <VAutocomplete v-model="eisaCreateForm.learnership_id" :label="t('lms_aqp_eisa.dialog.learnership') + ' *'"
                  :items="learnerships.map(l => ({title: l.title + (l.saqa_id ? ' (' + l.saqa_id + ')' : ''), value: l.id}))"
                  :error-messages="eisaCreateForm.errors.learnership_id" />
              </VCol>
              <VCol cols="12">
                <VAutocomplete v-model="eisaCreateForm.aqp_id" :label="t('lms_aqp_eisa.table.aqp') + ' *'"
                  :items="aqps.filter(a => a.is_active).map(a => ({title: a.acronym + ' — ' + a.name, value: a.id}))"
                  :error-messages="eisaCreateForm.errors.aqp_id" />
              </VCol>
              <VCol cols="12">
                <VAutocomplete v-model="eisaCreateForm.hr_person_id" :label="t('lms_aqp_eisa.table.learner') + ' *'"
                  :items="employees.map(e => ({title: e.first_name + ' ' + e.surname + (e.employee_number ? ' (' + e.employee_number + ')' : ''), value: e.id}))"
                  :error-messages="eisaCreateForm.errors.hr_person_id" />
              </VCol>
              <VCol cols="6">
                <VTextField v-model="eisaCreateForm.scheduled_date" :label="t('lms_aqp_eisa.table.date') + ' *'" type="date" />
              </VCol>
              <VCol cols="6">
                <VTextField v-model="eisaCreateForm.venue" :label="t('lms_aqp_eisa.dialog.venue')" />
              </VCol>
              <VCol cols="6">
                <VTextField v-model="eisaCreateForm.aqp_reference" :label="t('lms_aqp_eisa.dialog.aqp_reference')" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="eisaCreateForm.notes" :label="t('lms_aqp_eisa.dialog.notes')" rows="2" auto-grow />
              </VCol>
            </template>
            <template v-else>
              <VCol cols="6">
                <VTextField v-model="eisaEditForm.scheduled_date" :label="t('lms_aqp_eisa.table.date') + ' *'" type="date" />
              </VCol>
              <VCol cols="6">
                <VTextField v-model="eisaEditForm.venue" :label="t('lms_aqp_eisa.dialog.venue')" />
              </VCol>
              <VCol cols="6">
                <VSelect v-model="eisaEditForm.status" :label="t('lms_aqp_eisa.table.status') + ' *'"
                  :items="['scheduled','completed','absent','deferred','cancelled'].map(v => ({title:v,value:v}))" />
              </VCol>
              <VCol cols="6">
                <VSelect v-model="eisaEditForm.result" :label="t('lms_aqp_eisa.table.result')"
                  :items="[
                    {title:t('lms_aqp_eisa.competent'),value:'competent'},
                    {title:t('lms_aqp_eisa.not_yet_competent'),value:'not_yet_competent'},
                    {title:t('lms_aqp_eisa.absent'),value:'absent'},
                  ]" clearable />
              </VCol>
              <VCol cols="4">
                <VTextField v-model="eisaEditForm.score" :label="t('lms_aqp_eisa.table.score')" type="number" min="0" max="100" />
              </VCol>
              <VCol cols="4">
                <VTextField v-model="eisaEditForm.certificate_number" :label="t('lms_aqp_eisa.dialog.certificate_no')" />
              </VCol>
              <VCol cols="4">
                <VTextField v-model="eisaEditForm.certificate_issued_at" :label="t('lms_aqp_eisa.dialog.certificate_date')" type="date" />
              </VCol>
              <VCol cols="6">
                <VTextField v-model="eisaEditForm.aqp_reference" :label="t('lms_aqp_eisa.dialog.aqp_reference')" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="eisaEditForm.notes" :label="t('lms_aqp_eisa.dialog.notes')" rows="2" auto-grow />
              </VCol>
            </template>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="eisaDialog = false">{{ t('lms_aqp_eisa.dialog.cancel') }}</VBtn>
          <VBtn color="primary"
            :loading="editingEisa ? eisaEditForm.processing : eisaCreateForm.processing"
            @click="saveEisa">{{ t('lms_aqp_eisa.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
