<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { work_centre: { id: number; code: string; name: string }; availability: number | null; performance: number | null; quality: number | null; oee: number | null
  inputs: Record<string, number | null>; definitions: Record<string, string | null>; flags: string[]
  settings: { ideal_cycle_seconds: number | null; ideal_from_routing: boolean; exclude_planned_downtime: boolean } | null }

const props = defineProps<{ centres: Row[]; filters: { from: string; to: string }; can: { manage: boolean } }>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.maintenance.oee'), v, { preserveState: true, replace: true }), { deep: true })

const editing = ref<Row | null>(null)
const form = ref({ ideal_cycle_seconds: null as number | null, ideal_from_routing: false, exclude_planned_downtime: true })
function edit(r: Row) {
  editing.value = r
  form.value = { ideal_cycle_seconds: r.settings?.ideal_cycle_seconds ?? null, ideal_from_routing: r.settings?.ideal_from_routing ?? false, exclude_planned_downtime: r.settings?.exclude_planned_downtime ?? true }
}
const save = () => router.post(route('manufacturing.maintenance.oee.settings', editing.value!.work_centre.id), form.value, { preserveScroll: true, onSuccess: () => { editing.value = null } })
const color = (v: number | null) => v === null ? 'grey' : v >= 85 ? 'success' : v >= 60 ? 'warning' : 'error'
const show = (v: number | null) => v === null ? '—' : `${v}%`
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">OEE</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.maint.oee_subtitle') }}</p>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.to')" />
      </VCardText>
    </VCard>

    <VRow>
      <VCol v-for="r in centres" :key="r.work_centre.id" cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ r.work_centre.code }} — {{ r.work_centre.name }}</VCardTitle>
            <template #append><VBtn v-if="can.manage" size="small" variant="text" @click="edit(r)">{{ t('manufacturing.maint.definitions') }}</VBtn></template>
          </VCardItem>
          <VCardText>
            <div class="d-flex gap-4 flex-wrap mb-3">
              <div class="text-center"><VProgressCircular :model-value="r.oee ?? 0" :color="color(r.oee)" size="72" width="7">{{ show(r.oee) }}</VProgressCircular><div class="text-caption mt-1">OEE</div></div>
              <div v-for="k in ['availability', 'performance', 'quality']" :key="k" class="text-center">
                <VProgressCircular :model-value="(r as any)[k] ?? 0" :color="color((r as any)[k])" size="56" width="5">{{ show((r as any)[k]) }}</VProgressCircular>
                <div class="text-caption mt-1">{{ t(`manufacturing.maint.oee.${k}`) }}</div>
              </div>
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('manufacturing.maint.oee_inputs', { planned: r.inputs.planned_production_minutes, run: r.inputs.run_minutes, stops: r.inputs.unplanned_downtime_minutes, good: r.inputs.good, total: r.inputs.total }) }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.maint.ideal') }}: {{ r.definitions.ideal ?? '—' }} · {{ r.definitions.planned_downtime }}</div>
            <VAlert v-for="(fl, i) in r.flags" :key="i" type="warning" variant="tonal" density="compact" class="mt-2">{{ fl }}</VAlert>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="editing !== null" max-width="480" @update:model-value="v => { if (!v) editing = null }">
      <VCard v-if="editing" :title="t('manufacturing.maint.definitions')" :subtitle="editing.work_centre.code">
        <VCardText>
          <VSwitch v-model="form.ideal_from_routing" color="primary" hide-details :label="t('manufacturing.maint.ideal_from_routing')" />
          <VTextField v-if="!form.ideal_from_routing" v-model.number="form.ideal_cycle_seconds" type="number" min="0" step="any" :label="t('manufacturing.maint.ideal_seconds')" class="mt-2" />
          <VSwitch v-model="form.exclude_planned_downtime" color="primary" hide-details :label="t('manufacturing.maint.exclude_planned')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="save">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
