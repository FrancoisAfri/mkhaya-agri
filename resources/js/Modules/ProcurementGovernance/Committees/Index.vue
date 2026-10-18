<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FormErrors from '../Partials/FormErrors.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'

const { t } = useI18n()

defineProps<{
  committees: any[]
  officers: { id: number; name: string }[]
  committeeTypes: { value: string; title: string }[]
  roles: string[]
  noCompany?: boolean
  can: { manage: boolean }
}>()

const creating = ref(false)

const form = useForm({
  name: '',
  committee_type: '',
  terms_of_reference: '',
  chairperson_id: null as number | null,
  secretary_id: null as number | null,
  appointment_date: '',
  start_date: '',
  end_date: '',
  quorum: 3,
  requires_external_member: false,
})

const columns: Column[] = [
  {
    key: 'reference',
    label: t('procurement_governance.committees.reference'),
    kind: 'link',
    href: row => `/procurement-governance/committees/${row.uuid}`,
    width: '120px',
  },
  { key: 'name', label: t('procurement_governance.common.title') },
  { key: 'committee_type', label: t('procurement_governance.committees.type'), kind: 'muted', secondary: true },
  { key: 'chairperson', label: t('procurement_governance.committees.chairperson') },
  { key: 'member_count', label: t('procurement_governance.committees.members'), kind: 'count', align: 'end', width: '90px' },
  { key: 'end_date', label: t('procurement_governance.committees.term_ends'), kind: 'date', secondary: true },
  { key: 'status', label: t('procurement_governance.common.status'), kind: 'status', width: '150px' },
]

function submit() {
  form.post('/procurement-governance/committees', {
    preserveScroll: true,
    onSuccess: () => { creating.value = false; form.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.committees.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.committees.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="mdi-plus-circle-outline" @click="creating = true">
        {{ t('procurement_governance.committees.new') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <RegisterTable
      v-if="!noCompany"
      :rows="committees"
      :columns="columns"
      :search-keys="['reference', 'name', 'chairperson']"
      :empty-hint="t('procurement_governance.committees.empty')"
    />

    <VDialog v-model="creating" max-width="640" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.committees.new') }}</VCardTitle>
        <VCardText>
          <FormErrors :errors="form.errors" />

          <VRow dense>
            <VCol cols="12" md="8">
              <VTextField
                v-model="form.name" :label="t('procurement_governance.common.title')"
                :error-messages="form.errors.name" variant="outlined" density="comfortable" required
              />
            </VCol>
            <VCol cols="12" md="4">
              <!-- A configured list rather than free text: "Bid evaluation", "bid eval" and
                   "BEC" typed by three people are three committee types nobody can report on. -->
              <VSelect
                v-model="form.committee_type" :items="committeeTypes"
                :label="t('procurement_governance.committees.type')"
                :hint="t('procurement_governance.committees.type_hint')" persistent-hint
                :error-messages="form.errors.committee_type"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.chairperson_id"
                :items="officers.map(o => ({ title: o.name, value: o.id }))"
                :label="t('procurement_governance.committees.chairperson')"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.secretary_id"
                :items="officers.map(o => ({ title: o.name, value: o.id }))"
                :label="t('procurement_governance.committees.secretary')"
                :error-messages="form.errors.secretary_id"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.appointment_date" type="date"
                :label="t('procurement_governance.committees.appointed')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.start_date" type="date"
                :label="t('procurement_governance.committees.term_starts')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.end_date" type="date"
                :label="t('procurement_governance.committees.term_ends')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model.number="form.quorum" type="number" min="1" max="50"
                :label="t('procurement_governance.committees.quorum')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="8" class="d-flex align-center">
              <VCheckbox
                v-model="form.requires_external_member"
                :label="t('procurement_governance.committees.requires_external')"
                density="compact" hide-details
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.terms_of_reference"
                :label="t('procurement_governance.committees.terms')"
                variant="outlined" rows="3" auto-grow
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="creating = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="submit">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
