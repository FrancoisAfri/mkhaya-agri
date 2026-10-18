
<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute

const {t}=useI18n();

type Person = {
  id: number
  first_name: string
  last_name: string
  id_number: string | null
  email: string | null
  person_type: string
  registration_number: string | null
  seta_id: number | null
  seta: { id: number; acronym: string } | null
  registered_at: string | null
  expires_at: string | null
  nqf_levels: number[] | null
  fields_of_study: string[] | null
  status: string
  notes: string | null
}

const props = defineProps<{
  persons: Person[]
  setas: { id: number; name: string; acronym: string }[]
}>()

const confirmDialog = ref<InstanceType<typeof ConfirmDialog>>()
const dialog = ref(false)
const editing = ref<Person | null>(null)
const typeFilter = ref<string>('all')
const search = ref('')

const form = useForm({
  first_name: '',
  last_name: '',
  id_number: '',
  email: '',
  person_type: 'assessor',
  registration_number: '',
  seta_id: null as number | null,
  registered_at: '',
  expires_at: '',
  nqf_levels: [] as number[],
  fields_of_study: [] as string[],
  status: 'active',
  notes: '',
})

// Computed items for selects
const typeItems = computed(() => [
  { title: t('registration_person.types.assessor'), value: 'assessor' },
  { title: t('registration_person.types.moderator'), value: 'moderator' },
  { title: t('registration_person.types.assessor_moderator'), value: 'assessor_moderator' },
])

const statusItems = computed(() => [
  { title: t('registration_person.statuses.active'), value: 'active' },
  { title: t('registration_person.statuses.lapsed'), value: 'lapsed' },
  { title: t('registration_person.statuses.suspended'), value: 'suspended' },
  { title: t('registration_person.statuses.cancelled'), value: 'cancelled' },
])

const setaItems = computed(() =>
  props.setas.map(s => ({
    title: s.acronym + ' — ' + s.name,
    value: s.id
  }))
)

const nqfLevels = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]

const filtered = computed(() =>
  props.persons.filter(p => {
    const matchType = typeFilter.value === 'all' || p.person_type === typeFilter.value
    const matchSearch = !search.value ||
      `${p.first_name} ${p.last_name}`.toLowerCase().includes(search.value.toLowerCase())
    return matchType && matchSearch
  })
)

const typeColor: Record<string, string> = {
  assessor: 'primary',
  moderator: 'secondary',
  assessor_moderator: 'warning',
}

const statusColor: Record<string, string> = {
  active: 'success',
  lapsed: 'warning',
  suspended: 'error',
  cancelled: 'default',
}

function expiryChip(expires: string | null) {
  if (!expires) return null
  const d = new Date(expires)
  const now = new Date()
  const diff = (d.getTime() - now.getTime()) / 86400000

  if (diff < 0) {
    return { color: 'error', label: t('registration_person.expiry.expired') }
  }
  if (diff <= 30) {
    return { color: 'error', label: t('registration_person.expiry.days_left', { days: Math.round(diff) }) }
  }
  if (diff <= 90) {
    return { color: 'warning', label: t('registration_person.expiry.days_left', { days: Math.round(diff) }) }
  }
  return { color: 'success', label: d.toLocaleDateString('en-ZA') }
}

function openNew() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(p: Person) {
  editing.value = p
  form.first_name = p.first_name
  form.last_name = p.last_name
  form.id_number = p.id_number ?? ''
  form.email = p.email ?? ''
  form.person_type = p.person_type
  form.registration_number = p.registration_number ?? ''
  form.seta_id = p.seta_id
  form.registered_at = p.registered_at ? p.registered_at.substring(0, 10) : ''
  form.expires_at = p.expires_at ? p.expires_at.substring(0, 10) : ''
  form.nqf_levels = p.nqf_levels ?? []
  form.fields_of_study = p.fields_of_study ?? []
  form.status = p.status
  form.notes = p.notes ?? ''
  form.clearErrors()
  dialog.value = true
}

function save() {
  if (editing.value) {
    form.patch(route('lms.admin.qcto.persons.update', { person: editing.value.id }), {
      onSuccess: () => {
        dialog.value = false
      },
    })
  } else {
    form.post(route('lms.admin.qcto.persons.store'), {
      onSuccess: () => {
        dialog.value = false
        form.reset()
        form.clearErrors()
      },
    })
  }
}

function destroy(p: Person) {
  confirmDialog.value?.open(
    {
      title: t('registration_person.confirm_delete.title'),
      message: t('registration_person.confirm_delete.message', {
        first_name: p.first_name,
        last_name: p.last_name
      }),
      confirmText: t('registration_person.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      useForm({}).delete(route('lms.admin.qcto.persons.destroy', { person: p.id }))
    }
  )
}
</script>
<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('registration_person.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('registration_person.page_description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">
        {{ t('registration_person.add_person') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VTextField
        v-model="search"
        :placeholder="t('registration_person.search_placeholder')"
        prepend-inner-icon="tabler-search"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width:260px"
      />
      <VBtnToggle v-model="typeFilter" variant="outlined" density="compact">
        <VBtn value="all">{{ t('registration_person.filter_all') }}</VBtn>
        <VBtn value="assessor">{{ t('registration_person.filter_assessors') }}</VBtn>
        <VBtn value="moderator">{{ t('registration_person.filter_moderators') }}</VBtn>
        <VBtn value="assessor_moderator">{{ t('registration_person.filter_both') }}</VBtn>
      </VBtnToggle>
    </div>

    <!-- Table -->
    <VCard rounded="lg" elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('registration_person.table.name') }}</th>
            <th>{{ t('registration_person.table.type') }}</th>
            <th>{{ t('registration_person.table.seta') }}</th>
            <th>{{ t('registration_person.table.reg_number') }}</th>
            <th>{{ t('registration_person.table.expiry') }}</th>
            <th>{{ t('registration_person.table.nqf_levels') }}</th>
            <th>{{ t('registration_person.table.status') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in filtered" :key="p.id">
            <td>
              <p class="text-body-2 font-weight-medium mb-0">{{ p.first_name }} {{ p.last_name }}</p>
              <p v-if="p.email" class="text-caption text-medium-emphasis mb-0">{{ p.email }}</p>
            </td>
            <td>
              <VChip :color="typeColor[p.person_type]" size="x-small" variant="tonal" class="text-capitalize">
                {{ t(`registration_person.types.${p.person_type}`) }}
              </VChip>
            </td>
            <td>{{ p.seta?.acronym ?? '—' }}</td>
            <td>{{ p.registration_number ?? '—' }}</td>
            <td>
              <template v-if="expiryChip(p.expires_at)">
                <VChip :color="expiryChip(p.expires_at)!.color" size="x-small" variant="tonal">
                  {{ expiryChip(p.expires_at)!.label }}
                </VChip>
              </template>
              <span v-else class="text-medium-emphasis">{{ t('registration_person.expiry.not_set') }}</span>
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <VChip v-for="l in (p.nqf_levels ?? [])" :key="l" size="x-small" variant="outlined">NQF {{ l }}</VChip>
                <span v-if="!p.nqf_levels?.length" class="text-medium-emphasis">—</span>
              </div>
            </td>
            <td>
              <VChip :color="statusColor[p.status]" size="x-small" variant="tonal" class="text-capitalize">
                {{ t(`registration_person.statuses.${p.status}`) }}
              </VChip>
            </td>
            <td>
              <div class="d-flex gap-1">
                <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEdit(p)" />
                <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroy(p)" />
              </div>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="8" class="text-center text-medium-emphasis pa-6">{{ t('registration_person.no_persons') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Dialog -->
    <VDialog v-model="dialog" max-width="640">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">
          {{ editing ? t('registration_person.dialog.title_edit') : t('registration_person.dialog.title_new') }}
        </VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="6">
              <VTextField
                v-model="form.first_name"
                :label="t('registration_person.dialog.first_name_label')"
                :error-messages="form.errors.first_name"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.last_name"
                :label="t('registration_person.dialog.last_name_label')"
                :error-messages="form.errors.last_name"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.email"
                :label="t('registration_person.dialog.email_label')"
                :error-messages="form.errors.email"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.id_number"
                :label="t('registration_person.dialog.id_number_label')"
                type="text"
                inputmode="numeric"
                maxlength="13"
                :hint="t('registration_person.dialog.id_number_hint')"
                persistent-hint
                :error-messages="form.errors.id_number"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.person_type"
                :label="t('registration_person.dialog.type_label')"
                :items="typeItems"
                :error-messages="form.errors.person_type"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.seta_id"
                :label="t('registration_person.dialog.seta_label')"
                :items="setaItems"
                clearable
                :error-messages="form.errors.seta_id"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.registration_number"
                :label="t('registration_person.dialog.registration_number_label')"
                :error-messages="form.errors.registration_number"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.registered_at"
                :label="t('registration_person.dialog.registered_date_label')"
                type="date"
                :error-messages="form.errors.registered_at"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.expires_at"
                :label="t('registration_person.dialog.expiry_date_label')"
                type="date"
                :error-messages="form.errors.expires_at"
              />
            </VCol>
            <VCol cols="12">
              <VCombobox
                v-model="form.nqf_levels"
                :label="t('registration_person.dialog.nqf_levels_label')"
                :items="nqfLevels"
                multiple
                chips
                closable-chips
                :hint="t('registration_person.dialog.nqf_levels_hint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="12">
              <VCombobox
                v-model="form.fields_of_study"
                :label="t('registration_person.dialog.fields_of_study_label')"
                multiple
                chips
                closable-chips
                :hint="t('registration_person.dialog.fields_of_study_hint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.status"
                :label="t('registration_person.dialog.status_label')"
                :items="statusItems"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('registration_person.dialog.notes_label')"
                rows="2"
                auto-grow
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="dialog = false">{{ t('registration_person.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('registration_person.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>


