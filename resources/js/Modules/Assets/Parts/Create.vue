<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  locations: { value: number; title: string }[]
  suppliers: { value: number; title: string }[]
}>()

const form = useForm({
  part_number: '',
  name: '',
  description: '',
  category: '',
  unit_of_measure: 'each',
  unit_cost: 0,
  reorder_level: 0,
  reorder_quantity: 0,
  location_id: null as number | null,
  supplier_id: null as number | null,
  status: 'active',
  notes: '',
})

const uomOptions = ['each', 'box', 'pack', 'litre', 'metre', 'kg', 'roll', 'set']

function submit() {
  form.post(route('assets.parts.store'))
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">New Spare Part</h1>

    <VCard>
      <VCardItem>
        <VRow>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.part_number"
              label="Part number *"
              :error-messages="form.errors.part_number"
            />
          </VCol>
          <VCol cols="12" md="5">
            <VTextField
              v-model="form.name"
              label="Name *"
              :error-messages="form.errors.name"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model="form.category"
              label="Category"
              :error-messages="form.errors.category"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea v-model="form.description" label="Description" rows="2" />
          </VCol>

          <VCol cols="12" md="3">
            <VCombobox
              v-model="form.unit_of_measure"
              :items="uomOptions"
              label="Unit of measure *"
              :error-messages="form.errors.unit_of_measure"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model.number="form.unit_cost"
              type="number"
              min="0"
              step="0.01"
              label="Unit cost (R) *"
              :error-messages="form.errors.unit_cost"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model.number="form.reorder_level"
              type="number"
              min="0"
              step="0.01"
              label="Reorder level"
              hint="Alert when on-hand drops to/below this"
              persistent-hint
              :error-messages="form.errors.reorder_level"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model.number="form.reorder_quantity"
              type="number"
              min="0"
              step="0.01"
              label="Reorder quantity"
              hint="Suggested amount to order"
              persistent-hint
              :error-messages="form.errors.reorder_quantity"
            />
          </VCol>

          <VCol cols="12" md="4">
            <VSelect
              v-model="form.location_id"
              :items="props.locations"
              label="Storage location"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.supplier_id"
              :items="props.suppliers"
              label="Preferred supplier"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.status"
              :items="[
                { value: 'active', title: 'Active' },
                { value: 'inactive', title: 'Inactive' },
                { value: 'discontinued', title: 'Discontinued' },
              ]"
              label="Status"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea v-model="form.notes" label="Notes" rows="2" />
          </VCol>
        </VRow>

        <VAlert type="info" variant="tonal" density="compact" class="mt-2">
          Opening stock starts at zero — use the Receive action on the part once created to book in stock.
        </VAlert>

        <div class="d-flex justify-end gap-2 mt-4">
          <VBtn variant="text" :to="route('assets.parts.index')">Cancel</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="submit">Create Part</VBtn>
        </div>
      </VCardItem>
    </VCard>
  </div>
</template>
