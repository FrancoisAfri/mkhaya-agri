<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('payroll.deduction_references.' + key, a ?? {})

interface Beneficiary { uuid: string; name: string; type: string | null; needs_file: boolean }
interface Reference {
  uuid: string; employee_id: number; employee: string; employee_number: string | null
  reference: string; secondary_reference: string | null; deduction_type: string | null
  effective_from: string | null; effective_to: string | null
  instalment: number | null; total_amount: number | null; active: boolean
}

const props = defineProps<{
  beneficiaries: Beneficiary[]
  selected: { uuid: string; name: string } | null
  references: Reference[]
  employees: { id: number; name: string; number: string | null }[]
}>()

const rand = (n: number | null) => (n === null ? '—' : 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 }))

const current = computed(() => props.beneficiaries.find(b => b.uuid === props.selected?.uuid) ?? null)

function pick(uuid: string) {
  router.get(route('payroll.deduction-references.index'), { beneficiary: uuid }, { preserveState: true, preserveScroll: true, replace: true })
}

const employeeItems = computed(() => props.employees.map(e => ({
  value: e.id,
  title: e.name,
  subtitle: e.number ?? '',
})))

const dialog = ref(false)
const form = useForm({
  beneficiary: '', employee_id: null as number | null, reference: '',
  secondary_reference: '', deduction_type: '', effective_from: '', effective_to: '',
  instalment: null as number | null, total_amount: null as number | null, notes: '',
})

function open() {
  form.reset()
  form.beneficiary = props.selected?.uuid ?? ''
  dialog.value = true
}

function save() {
  form.post(route('payroll.deduction-references.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

function end(reference: Reference) {
  router.delete(route('payroll.deduction-references.destroy', reference.uuid), { preserveScroll: true })
}
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="mb-6">
      <div class="text-overline text-medium-emphasis">{{ d('overline') }}</div>
      <h1 class="text-h5 font-weight-bold mb-0">{{ d('page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ d('description') }}</p>
    </div>

    <VRow>
      <VCol cols="12" md="3">
        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ d('funds') }}</VCardTitle>
          <VList density="compact" nav>
            <VListItem
              v-for="b in beneficiaries"
              :key="b.uuid"
              :active="b.uuid === selected?.uuid"
              @click="pick(b.uuid)"
            >
              <VListItemTitle class="text-body-2">{{ b.name }}</VListItemTitle>
              <!--
                A fund set up for a machine-read file cannot be sent one until every employee on it
                has a reference, so it is worth saying which funds those are before somebody
                discovers it at the point of download.
              -->
              <VListItemSubtitle v-if="b.needs_file" class="text-caption">
                <VChip size="x-small" color="primary" variant="tonal">{{ d('file_required') }}</VChip>
              </VListItemSubtitle>
            </VListItem>
          </VList>
          <VCardText v-if="!beneficiaries.length" class="text-caption text-medium-emphasis">
            {{ d('no_funds') }}
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="9">
        <VCard border>
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-subtitle-1">{{ selected?.name ?? d('page_title') }}</span>
            <VBtn v-if="selected" color="primary" size="small" prepend-icon="tabler-plus" @click="open">
              {{ d('add') }}
            </VBtn>
          </VCardTitle>

          <VAlert v-if="current?.needs_file" type="info" variant="tonal" density="compact" class="mx-4 mb-2">
            {{ d('file_note') }}
          </VAlert>

          <VTable v-if="references.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ d('table.employee') }}</th>
                <th>{{ d('table.reference') }}</th>
                <th>{{ d('table.applies') }}</th>
                <th class="text-right">{{ d('table.instalment') }}</th>
                <th class="text-right">{{ d('table.total') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in references" :key="r.uuid" :class="{ 'text-medium-emphasis': !r.active }">
                <td>
                  <div class="text-body-2 font-weight-medium">{{ r.employee }}</div>
                  <div v-if="r.employee_number" class="text-caption text-medium-emphasis">{{ r.employee_number }}</div>
                </td>
                <td>
                  <div class="text-body-2">{{ r.reference }}</div>
                  <div v-if="r.secondary_reference" class="text-caption text-medium-emphasis">{{ r.secondary_reference }}</div>
                </td>
                <td class="text-caption">
                  {{ r.effective_from ?? d('table.always') }} → {{ r.effective_to ?? d('table.open') }}
                </td>
                <td class="text-right">{{ rand(r.instalment) }}</td>
                <td class="text-right">{{ rand(r.total_amount) }}</td>
                <td class="text-right">
                  <VChip v-if="!r.active" size="x-small" variant="tonal">{{ d('ended') }}</VChip>
                  <VBtn v-else size="small" variant="text" color="error" @click="end(r)">{{ d('end') }}</VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ selected ? d('empty') : d('no_funds') }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="620">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('add') }} — {{ selected?.name }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.employee_id"
                :items="employeeItems"
                :label="d('form.employee')"
                variant="outlined" density="comfortable"
                :error-messages="form.errors.employee_id"
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.reference" :label="d('form.reference')"
                :hint="d('form.reference_hint')" persistent-hint
                variant="outlined" density="comfortable" :error-messages="form.errors.reference"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.secondary_reference" :label="d('form.secondary_reference')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.effective_from" type="date" :label="d('form.effective_from')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.effective_to" type="date" :label="d('form.effective_to')"
                :hint="d('form.effective_to_hint')" persistent-hint
                variant="outlined" density="comfortable" :error-messages="form.errors.effective_to"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="form.instalment" type="number" :label="d('form.instalment')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="form.total_amount" type="number" :label="d('form.total_amount')" variant="outlined" density="comfortable" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ d('save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
