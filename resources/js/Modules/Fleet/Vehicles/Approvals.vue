<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Vehicle {
  id: number; uuid: string; registration_number: string; fleet_number: string | null
  make: string | null; model: string | null; year: number | null
  vehicle_type: string | null; fuel_type: string | null
  colour: string | null; vin_number: string | null
  status: string; approval_status: string
  location: string | null; driver_name: string | null
  purchase_date: string | null; purchase_price: number | null
  notes: string | null
}

const props = defineProps<{ vehicles: Vehicle[] }>()

const rejectDialog = ref(false)
const rejectTarget = ref<Vehicle | null>(null)
const rejectReason = ref('')
const processing    = ref(false)

function openReject(v: Vehicle) {
  rejectTarget.value = v
  rejectReason.value = ''
  rejectDialog.value = true
}

function doApprove(v: Vehicle) {
  processing.value = true
  router.patch(route('fleet.vehicles.approve', v.uuid), {}, {
    preserveScroll: true,
    onFinish: () => { processing.value = false },
  })
}

function doReject() {
  if (!rejectTarget.value) return
  processing.value = true
  router.patch(route('fleet.vehicles.reject', rejectTarget.value.uuid), { reason: rejectReason.value }, {
    preserveScroll: true,
    onSuccess: () => { rejectDialog.value = false },
    onFinish: () => { processing.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div class="d-flex align-center ga-2">
        <VBtn icon variant="text" :href="route('fleet.vehicles.index')"><VIcon>tabler-arrow-left</VIcon></VBtn>
        <div>
          <h1 class="text-h5 font-weight-bold">{{ t('fleet_vehicles_approvals.page_title') }}</h1>
          <p class="text-caption text-medium-emphasis">
            {{ t('fleet_vehicles_approvals.description', { count: vehicles.length }) }}
          </p>
        </div>
      </div>
    </div>

    <VCard v-if="!vehicles.length" class="text-center pa-12">
      <VIcon size="64" color="success" class="mb-3">tabler-circle-check</VIcon>
      <div class="text-h6 mb-2">{{ t('fleet_vehicles_approvals.all_clear') }}</div>
      <div class="text-medium-emphasis">{{ t('fleet_vehicles_approvals.no_pending') }}</div>
    </VCard>

    <VRow v-else>
      <VCol v-for="v in vehicles" :key="v.id" cols="12" md="6" lg="4">
        <VCard variant="outlined" :color="v.approval_status === 'pending' ? 'warning' : undefined">
          <VCardTitle class="d-flex align-center ga-2 pa-4 pb-2">
            <VIcon color="warning">tabler-car</VIcon>
            {{ v.registration_number }}
            <VChip size="x-small" color="warning" label class="ml-auto">{{ t('fleet_vehicles_approvals.card.pending') }}</VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VList density="compact" class="pa-0">
              <VListItem :title="t('fleet_vehicles_approvals.card.make_model')" :subtitle="`${v.make ?? '—'} ${v.model ?? ''}`.trim() || '—'" />
              <VListItem :title="t('fleet_vehicles_approvals.card.year')" :subtitle="v.year ? String(v.year) : '—'" />
              <VListItem :title="t('fleet_vehicles_approvals.card.type')" :subtitle="v.vehicle_type ?? '—'" />
              <VListItem :title="t('fleet_vehicles_approvals.card.fuel')" :subtitle="v.fuel_type ?? '—'" />
              <VListItem :title="t('fleet_vehicles_approvals.card.colour')" :subtitle="v.colour ?? '—'" />
              <VListItem :title="t('fleet_vehicles_approvals.card.vin_chassis')" :subtitle="v.vin_number ?? '—'" />
              <VListItem :title="t('fleet_vehicles_approvals.card.location')" :subtitle="v.location ?? '—'" />
              <VListItem v-if="v.purchase_price" :title="t('fleet_vehicles_approvals.card.purchase_price')" :subtitle="`R ${Number(v.purchase_price).toLocaleString()}`" />
              <VListItem v-if="v.notes" :title="t('fleet_vehicles_approvals.card.notes')" :subtitle="v.notes" />
            </VList>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-3">
            <VBtn color="error" variant="tonal" size="small" prepend-icon="tabler-circle-x"
              @click="openReject(v)">{{ t('fleet_vehicles_approvals.card.reject') }}</VBtn>
            <VSpacer />
            <VBtn :href="route('fleet.vehicles.show', v.uuid)" size="small" variant="text" prepend-icon="tabler-eye">{{ t('fleet_vehicles_approvals.card.view') }}</VBtn>
            <VBtn color="success" variant="flat" size="small" prepend-icon="tabler-check" :loading="processing"
              @click="doApprove(v)">{{ t('fleet_vehicles_approvals.card.approve') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="rejectDialog" max-width="400" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ t('fleet_vehicles_approvals.reject_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="mb-3">{{ t('fleet_vehicles_approvals.reject_dialog.message', { registration: rejectTarget?.registration_number }) }}</p>
          <VTextarea v-model="rejectReason" :label="t('fleet_vehicles_approvals.reject_dialog.reason')" variant="outlined" density="comfortable" rows="3" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog=false">{{ t('fleet_vehicles_approvals.reject_dialog.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="processing" @click="doReject">{{ t('fleet_vehicles_approvals.reject_dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
