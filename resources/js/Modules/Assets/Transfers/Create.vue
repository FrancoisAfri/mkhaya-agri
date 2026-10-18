<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  assets: { uuid: string; name: string; asset_number: string; location_id: number | null }[]
  locations: { id: number; name: string; code: string }[]
  employees: { id: number; name: string }[]
}>()

const form = reactive({
  asset_uuid:       null as string | null,
  from_location_id: null as number | null,
  to_location_id:   null as number | null,
  to_user_id:       null as number | null,
  reason:           '',
  transfer_date:    new Date().toISOString().slice(0, 10),
})

function submit() {
  if (!form.asset_uuid) return
  router.post(route('assets.transfers.store', form.asset_uuid), {
    from_location_id: form.from_location_id,
    to_location_id:   form.to_location_id,
    to_user_id:       form.to_user_id,
    reason:           form.reason,
    transfer_date:    form.transfer_date,
  })
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">Request Asset Transfer</h1>

    <VAlert type="info" variant="tonal" class="mb-4">Transfers require approval before they take effect.</VAlert>

    <VCard>
      <VCardItem>
        <VRow>
          <VCol cols="12" md="6">
            <VAutocomplete v-model="form.asset_uuid"
              :items="props.assets.map(a => ({ value: a.uuid, title: `${a.name} (${a.asset_number})` }))"
              label="Asset" />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="form.from_location_id"
              :items="props.locations.map(l => ({ value: l.id, title: l.name }))"
              label="From location" />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="form.to_location_id"
              :items="props.locations.map(l => ({ value: l.id, title: l.name }))"
              label="To location" clearable />
          </VCol>
          <VCol cols="12" md="6">
            <VAutocomplete v-model="form.to_user_id"
              :items="props.employees.map(e => ({ value: e.id, title: e.name }))"
              label="To user (optional)" clearable />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="form.transfer_date" type="date" label="Transfer date" />
          </VCol>
          <VCol cols="12">
            <VTextarea v-model="form.reason" rows="3" label="Reason" />
          </VCol>
        </VRow>

        <div class="d-flex justify-end gap-2 mt-4">
          <VBtn variant="text" :to="route('assets.transfers.index')">Cancel</VBtn>
          <VBtn color="primary" @click="submit">Submit</VBtn>
        </div>
      </VCardItem>
    </VCard>
  </div>
</template>
