<script setup lang="ts">
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  assets: { id: number; uuid: string; name: string; asset_number: string }[]
  employees: { id: number; name: string }[]
}>()

const form = reactive({
  asset_uuid:       null as string | null,
  assigned_to:      null as number | null,
  assignment_type:  'permanent',
  effective_date:   new Date().toISOString().slice(0, 10),
  return_due_date:  null as string | null,
  notes:            '',
})

const requiresReturnDate = computed(() => ['temporary', 'loan'].includes(form.assignment_type))

function submit() {
  if (!form.asset_uuid) return
  router.post(route('assets.assign', form.asset_uuid), {
    assigned_to:     form.assigned_to,
    assignment_type: form.assignment_type,
    effective_date:  form.effective_date,
    return_due_date: form.return_due_date,
    notes:           form.notes,
  })
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-6">New Assignment</h1>

    <VCard>
      <VCardItem>
        <VRow>
          <VCol cols="12" md="6">
            <VAutocomplete v-model="form.asset_uuid"
              :items="props.assets.map(a => ({ value: a.uuid, title: `${a.name} (${a.asset_number})` }))"
              label="Asset" />
          </VCol>
          <VCol cols="12" md="6">
            <VAutocomplete v-model="form.assigned_to"
              :items="props.employees.map(e => ({ value: e.id, title: e.name }))"
              label="Assign to" />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect v-model="form.assignment_type"
              :items="[{v:'permanent',t:'Permanent'},{v:'temporary',t:'Temporary'},{v:'loan',t:'Loan'},{v:'shared',t:'Shared'}]"
              item-title="t" item-value="v" label="Type" />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField v-model="form.effective_date" type="date" label="Effective date" />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField v-model="form.return_due_date" type="date" label="Return due date"
              :required="requiresReturnDate" :hint="requiresReturnDate ? 'Required for this type' : ''" persistent-hint />
          </VCol>
          <VCol cols="12">
            <VTextarea v-model="form.notes" label="Notes" rows="3" />
          </VCol>
        </VRow>

        <div class="d-flex justify-end gap-2 mt-4">
          <VBtn variant="text" :to="route('assets.assignments.index')">Cancel</VBtn>
          <VBtn color="primary" @click="submit">Create</VBtn>
        </div>
      </VCardItem>
    </VCard>
  </div>
</template>
