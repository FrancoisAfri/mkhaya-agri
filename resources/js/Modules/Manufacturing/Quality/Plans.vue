<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PlanForm from './PlanForm.vue'
import type { PlanOptions } from './PlanForm.vue'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; code: string; name: string; stage: string; subject_code: string | null; subject_name: string | null
  operation: string | null; sample_size: number | null; every_qty: number | null; blocks_production: boolean
  requires_certificate: boolean; is_active: boolean; characteristics_count: number
}

const props = defineProps<{
  plans: { data: Row[]; current_page: number; last_page: number }
  filters: { stage?: string; search?: string }
  stages: string[]
  options: PlanOptions | null
  can: { manage: boolean }
}>()

const search = ref(props.filters.search ?? '')
const stage = ref<string | null>(props.filters.stage ?? null)
let timer: ReturnType<typeof setTimeout> | undefined
function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.quality.plans.index'), { search: search.value || undefined, stage: stage.value || undefined, ...extra },
    { preserveState: true, preserveScroll: true, replace: true })
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch(stage, () => apply())

const dialog = ref(false)
const form = useForm({
  code: '', name: '', stage: 'FINAL', stock_item_id: null as number | null, item_uuid: null as string | null,
  supplier_id: null as number | null, routing_operation_uuid: null as string | null, sample_size: null as number | null,
  every_qty: null as number | null, blocks_production: true, requires_certificate: false, is_active: true, notes: '',
})
function save() {
  form.post(route('manufacturing.quality.plans.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}

const headers = [
  { title: t('manufacturing.common.code'), key: 'code' },
  { title: t('manufacturing.quality.stage_label'), key: 'stage' },
  { title: t('manufacturing.quality.applies_to'), key: 'subject_code' },
  { title: t('manufacturing.quality.checks'), key: 'characteristics_count', align: 'end' as const },
  { title: t('manufacturing.quality.sample_size'), key: 'sample_size', align: 'end' as const },
  { title: '', key: 'flags', sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.quality.plans_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.quality.plans_subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('manufacturing.quality.new_plan') }}</VBtn>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px" prepend-inner-icon="tabler-search" :label="t('manufacturing.quality.search_plans')" />
        <VSelect v-model="stage" density="compact" hide-details clearable style="max-width: 220px"
                 :items="stages.map(s => ({ title: t(`manufacturing.quality.stage.${s}`), value: s }))" :label="t('manufacturing.quality.stage_label')" />
      </VCardText>
      <VDataTable :headers="headers" :items="plans.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.quality.plans.show', item.id))">
        <template #item.code="{ item }">
          <span class="font-weight-medium">{{ item.code }}</span>
          <div class="text-caption text-medium-emphasis">{{ item.name }}</div>
        </template>
        <template #item.stage="{ item }">{{ t(`manufacturing.quality.stage.${item.stage}`) }}</template>
        <template #item.subject_code="{ item }">
          <div>{{ item.subject_code }} — {{ item.subject_name }}</div>
          <div v-if="item.operation" class="text-caption text-medium-emphasis">{{ item.operation }}</div>
        </template>
        <template #item.sample_size="{ item }">{{ item.sample_size ?? 1 }}</template>
        <template #item.flags="{ item }">
          <VChip v-if="!item.is_active" size="x-small" label class="me-1">{{ t('manufacturing.quality.inactive') }}</VChip>
          <VChip v-if="item.blocks_production" size="x-small" label color="error" variant="tonal" class="me-1">{{ t('manufacturing.quality.blocks') }}</VChip>
          <VChip v-if="item.requires_certificate" size="x-small" label color="info" variant="tonal">{{ t('manufacturing.quality.certificate') }}</VChip>
        </template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.quality.no_plans') }}</div></template>
      </VDataTable>
      <VCardText v-if="plans.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="plans.current_page" :length="plans.last_page" total-visible="7" @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-if="options" v-model="dialog" max-width="680">
      <VCard :title="t('manufacturing.quality.new_plan')">
        <VCardText><PlanForm :form="form" :options="options" :stages="stages" /></VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.code || !form.name" @click="save">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
