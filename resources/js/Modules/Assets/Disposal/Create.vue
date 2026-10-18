<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{ assets: { uuid: string; name: string; asset_number: string }[] }>()

const form = reactive({
  asset_uuid:      null as string | null,
  disposal_method: 'sold',
  proceeds:        null as number | null,
  buyer_info:      '',
  reason:          '',
  disposal_date:   new Date().toISOString().slice(0, 10),
})

function submit() {
  if (!form.asset_uuid) return
  router.post(route('assets.disposal.store', form.asset_uuid), {
    disposal_method: form.disposal_method,
    proceeds:        form.proceeds,
    buyer_info:      form.buyer_info || null,
    reason:          form.reason,
    disposal_date:   form.disposal_date,
  })
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">Request Disposal</h1>

    <VAlert type="info" class="mb-4" variant="tonal">
      Disposal requests require approval. The asset's book value will be snapshotted at submission.
    </VAlert>

    <VCard>
      <VCardItem>
        <VRow>
          <VCol cols="12" md="6">
            <VAutocomplete v-model="form.asset_uuid"
              :items="props.assets.map(a => ({ value: a.uuid, title: `${a.name} (${a.asset_number})` }))"
              label="Asset" />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="form.disposal_method"
              :items="[{v:'sold',t:'Sold'},{v:'scrapped',t:'Scrapped'},{v:'donated',t:'Donated'},{v:'destroyed',t:'Destroyed'},{v:'traded',t:'Traded'},{v:'written_off',t:'Written off'}]"
              item-title="t" item-value="v" label="Method" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model.number="form.proceeds" type="number" min="0" step="0.01" label="Expected proceeds (R)" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="form.disposal_date" type="date" label="Disposal date" />
          </VCol>
          <VCol cols="12">
            <VTextarea v-model="form.buyer_info" label="Buyer / recipient information" rows="2" />
          </VCol>
          <VCol cols="12">
            <VTextarea v-model="form.reason" label="Reason for disposal (min 30 chars)" rows="4" :counter="2000" />
          </VCol>
        </VRow>

        <div class="d-flex justify-end gap-2 mt-4">
          <VBtn variant="text" :to="route('assets.disposal.index')">Cancel</VBtn>
          <VBtn color="primary" @click="submit">Submit for Approval</VBtn>
        </div>
      </VCardItem>
    </VCard>
  </div>
</template>
