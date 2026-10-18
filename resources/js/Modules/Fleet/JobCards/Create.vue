<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  vehicles: { id: number; registration_number: string; make: string; model: string }[]
  mechanics: { id: number; name: string }[]
  serviceTypes: { id: number; name: string; standard_hours: number | null; standard_cost: number | null }[]
  settings: { default_labour_rate: number }
}>()

const form = useForm({
  vehicle_id: null as number | null,
  mechanic_id: null as number | null,
  title: '',
  fault_description: '',
  work_requested: '',
  priority: 'normal',
  odometer: null as number | null,
  due_date: '',
  labour_hours: 0,
  labour_rate: props.settings?.default_labour_rate ?? 0,
  other_cost: 0,
  service_lines: [] as { service_type_id: number | null; name: string; hours: number; cost: number }[],
})

function addServiceLine() {
  form.service_lines.push({ service_type_id: null, name: '', hours: 0, cost: 0 })
}

/** Picking a catalogue service pre-fills its standard hours and cost. */
function onServicePicked(index: number, id: number | null) {
  const type = props.serviceTypes.find(s => s.id === id)
  if (!type) return
  form.service_lines[index].name = type.name
  form.service_lines[index].hours = Number(type.standard_hours ?? 0)
  form.service_lines[index].cost = Number(type.standard_cost ?? 0)
}

const submit = () => form.post(route('fleet.job-cards.store'))
</script>

<template>
  <VContainer fluid class="pa-6" style="max-width: 1000px;">
    <h1 class="text-h5 font-weight-bold mb-1">{{ t('fleet_job_cards.new_card') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">{{ t('fleet_job_cards.new_card_subtitle') }}</p>

    <VForm @submit.prevent="submit">
      <VCard class="mb-4">
        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.vehicle_id"
                :items="props.vehicles"
                :item-title="(v: any) => `${v.registration_number} — ${v.make} ${v.model}`"
                item-value="id"
                :label="t('fleet_job_cards.vehicle')"
                density="compact"
                :error-messages="form.errors.vehicle_id"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.mechanic_id"
                :items="props.mechanics"
                item-title="name"
                item-value="id"
                :label="t('fleet_job_cards.mechanic')"
                density="compact"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="form.title" :label="t('fleet_job_cards.col_title')" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea v-model="form.fault_description" :label="t('fleet_job_cards.fault_description')" rows="3" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea v-model="form.work_requested" :label="t('fleet_job_cards.work_requested')" rows="3" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect
                v-model="form.priority"
                :items="[
                  { title: t('fleet_job_cards.priority.low'), value: 'low' },
                  { title: t('fleet_job_cards.priority.normal'), value: 'normal' },
                  { title: t('fleet_job_cards.priority.high'), value: 'high' },
                  { title: t('fleet_job_cards.priority.emergency'), value: 'emergency' },
                ]"
                :label="t('fleet_job_cards.priority_label')"
                density="compact"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="form.odometer" type="number" :label="t('fleet_job_cards.odometer')" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model="form.due_date" type="date" :label="t('fleet_job_cards.due')" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="form.labour_rate" type="number" step="0.01" :label="t('fleet_job_cards.labour_rate')" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard class="mb-4">
        <VCardTitle class="d-flex align-center justify-space-between">
          <span class="text-subtitle-1">{{ t('fleet_job_cards.services') }}</span>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addServiceLine">
            {{ t('fleet_job_cards.add_service') }}
          </VBtn>
        </VCardTitle>
        <VCardText>
          <VRow v-for="(line, i) in form.service_lines" :key="i" dense align="center">
            <VCol cols="12" md="5">
              <VSelect
                v-model="line.service_type_id"
                :items="props.serviceTypes"
                item-title="name"
                item-value="id"
                :label="t('fleet_job_cards.service_type')"
                density="compact"
                @update:model-value="(v: any) => onServicePicked(i, v)"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="line.hours" type="number" step="0.25" :label="t('fleet_job_cards.hours')" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="line.cost" type="number" step="0.01" :label="t('fleet_job_cards.cost')" density="compact" />
            </VCol>
            <VCol cols="12" md="1">
              <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="form.service_lines.splice(i, 1)" />
            </VCol>
          </VRow>
          <p v-if="!form.service_lines.length" class="text-caption text-medium-emphasis">
            {{ t('fleet_job_cards.no_services_yet') }}
          </p>
        </VCardText>
      </VCard>

      <div class="d-flex justify-end gap-2">
        <VBtn variant="text" :href="route('fleet.job-cards.index')">{{ t('fleet_job_cards.back') }}</VBtn>
        <VBtn type="submit" color="primary" :loading="form.processing">{{ t('fleet_job_cards.create_card') }}</VBtn>
      </div>
    </VForm>
  </VContainer>
</template>
