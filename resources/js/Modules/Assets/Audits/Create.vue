<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  locations: { id: number; name: string }[]
}>()

const form = useForm({
  name: '',
  location_id: null as number | null,
  notes: '',
})

const locationItems = [
  { title: 'All locations (whole company)', value: null },
  ...props.locations.map(l => ({ title: l.name, value: l.id })),
]

function submit() {
  form.post(route('assets.audits.store'))
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn icon variant="text" @click="router.visit(route('assets.audits.index'))">
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">New Asset Audit</h4>
        <p class="text-medium-emphasis mb-0">Snapshot the current assets and start scanning</p>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard elevation="0" border>
          <VCardText class="pa-6">
            <VForm @submit.prevent="submit">
              <VTextField
                v-model="form.name"
                label="Audit name"
                placeholder="e.g. Q3 2026 Head Office Stock-take"
                :error-messages="form.errors.name"
                class="mb-4"
              />

              <VSelect
                v-model="form.location_id"
                label="Location (optional)"
                :items="locationItems"
                :error-messages="form.errors.location_id"
                hint="Leave as 'All locations' to audit every asset"
                persistent-hint
                class="mb-4"
              />

              <VTextarea
                v-model="form.notes"
                label="Notes (optional)"
                rows="3"
                :error-messages="form.errors.notes"
                class="mb-4"
              />

              <div class="d-flex gap-3">
                <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-player-play">
                  Start Audit
                </VBtn>
                <VBtn variant="tonal" @click="router.visit(route('assets.audits.index'))">
                  Cancel
                </VBtn>
              </div>
            </VForm>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard elevation="0" border color="rgba(115,103,240,0.06)">
          <VCardText class="pa-6">
            <VIcon icon="tabler-info-circle" color="primary" size="28" class="mb-3" />
            <p class="text-body-1 font-weight-medium mb-2">How it works</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Starting an audit takes a snapshot of every asset (optionally filtered
              to a location) as an <strong>expected</strong> list. Walk the room and
              scan each asset's barcode or asset number. When you finish, complete the
              audit — anything not scanned is flagged <strong>missing</strong>, and
              anything scanned that wasn't expected is flagged <strong>unexpected</strong>.
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
