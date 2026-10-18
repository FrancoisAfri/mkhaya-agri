<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  assets: { uuid: string; name: string; asset_number: string }[]
  options: {
    priorities: { value: string; label: string }[]
    statuses: { value: string; label: string }[]
    types: { value: string; label: string }[]
  }
}>()

const form = useForm({
  asset_uuid: '',
  title: '',
  description: '',
  type: 'corrective',
  priority: 'medium',
  scheduled_for: '',
  assigned_to: null as number | null,
})

const assetItems = props.assets.map(a => ({
  title: `${a.name} (${a.asset_number})`,
  value: a.uuid,
}))

function submit() {
  form.post(route('assets.work-orders.store'))
}
</script>

<template>
  <div class="pa-6" style="max-width:820px">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn icon="tabler-arrow-left" variant="text" :href="route('assets.work-orders.index')" />
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">New Work Order</h4>
        <p class="text-medium-emphasis mb-0">Raise a work order against an asset.</p>
      </div>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-6">
        <VRow>
          <VCol cols="12">
            <VSelect
              v-model="form.asset_uuid"
              :items="assetItems"
              item-title="title"
              item-value="value"
              label="Asset *"
              :error-messages="form.errors.asset_uuid"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VTextField
              v-model="form.title"
              label="Title *"
              :error-messages="form.errors.title"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.description"
              label="Description"
              rows="3"
              :error-messages="form.errors.description"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="form.type"
              :items="options.types"
              item-title="label"
              item-value="value"
              label="Type *"
              :error-messages="form.errors.type"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="form.priority"
              :items="options.priorities"
              item-title="label"
              item-value="value"
              label="Priority *"
              :error-messages="form.errors.priority"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VTextField
              v-model="form.scheduled_for"
              type="date"
              label="Scheduled for"
              :error-messages="form.errors.scheduled_for"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VTextField
              v-model.number="form.assigned_to"
              type="number"
              label="Assign to (user ID)"
              hint="Technician user ID (optional)"
              persistent-hint
              :error-messages="form.errors.assigned_to"
              density="comfortable"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />

      <VCardActions class="pa-4 justify-end gap-2">
        <VBtn variant="text" :href="route('assets.work-orders.index')">Cancel</VBtn>
        <VBtn color="primary" :loading="form.processing" @click="submit">Create Work Order</VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
