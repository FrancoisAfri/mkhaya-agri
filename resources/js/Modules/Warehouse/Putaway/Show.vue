<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  task: Record<string, any> & { reasons: { chosen?: string[]; rejected?: Record<string, string> } }
  bins: Array<{ id: string; title: string }>
  can: { move: boolean; override: boolean }
}>()

const remaining = computed(() => Math.round((props.task.qty - props.task.done_qty) * 10000) / 10000)
const open = computed(() => ['OPEN', 'IN_PROGRESS', 'PARTIAL'].includes(props.task.status))
const form = useForm({ action: 'complete', bin: props.task.suggested_id as string | null, qty: remaining.value, item_scan: '', bin_scan: '', reason: '' })
const directedLock = computed(() => props.task.policy === 'DIRECTED' && !props.can.override)

function complete() {
  form.action = 'complete'
  form.post(route('warehouse.floor.putaway.act', props.task.id), { preserveScroll: true, onSuccess: () => { form.item_scan = ''; form.bin_scan = '' } })
}
const reasonDialog = ref<string | null>(null)
function act(action: string) {
  if (['fail', 'cancel'].includes(action) && !form.reason) { reasonDialog.value = action; return }
  router.post(route('warehouse.floor.putaway.act', props.task.id), { action, reason: form.reason }, { preserveScroll: true, onSuccess: () => { reasonDialog.value = null } })
}
</script>

<template>
  <div class="pa-4" style="max-width: 760px">
    <div class="text-caption"><a :href="route('warehouse.floor.putaway.index')">{{ t('warehouse_management.putaway.title') }}</a></div>
    <h4 class="text-h4 mb-1">{{ task.number }} · {{ task.item }}</h4>
    <p class="text-body-2 text-medium-emphasis">{{ task.item_name }}<template v-if="task.lot"> · {{ t('warehouse_management.movements.lot', { lot: task.lot }) }}</template>
      · {{ t(`warehouse_management.stock_state.${task.stock_status}`) }} · <VChip size="x-small" variant="tonal">{{ t(`warehouse_management.task_status.${task.status}`) }}</VChip></p>
    <RefusalAlert />

    <VRow class="mb-2">
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption">{{ t('warehouse_management.putaway.from') }}</div><div class="text-h5">{{ task.from ?? t('warehouse_management.stock.no_bin') }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="4"><VCard border color="primary" variant="tonal"><VCardText><div class="text-caption">{{ t('warehouse_management.putaway.suggested') }}</div><div class="text-h5">{{ task.suggested ?? '—' }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption">{{ t('warehouse_management.putaway.remaining') }}</div><div class="text-h5">{{ remaining }} {{ task.uom }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border class="mb-4">
      <VCardText>
        <div v-if="task.reasons.chosen?.length" class="text-body-2"><strong>{{ t('warehouse_management.putaway.why') }}</strong> {{ task.reasons.chosen.join('; ') }}.</div>
        <details v-if="task.reasons.rejected && Object.keys(task.reasons.rejected).length" class="mt-1 text-caption">
          <summary>{{ t('warehouse_management.putaway.ruled_out', { n: Object.keys(task.reasons.rejected).length }) }}</summary>
          <div v-for="(why, code) in task.reasons.rejected" :key="code">{{ code }}: {{ why }}</div>
        </details>
        <div class="text-caption text-medium-emphasis mt-1">{{ t(`warehouse_management.putaway.policy.${task.policy}`) }}</div>
      </VCardText>
    </VCard>

    <VCard v-if="open && can.move" border>
      <VCardText>
        <VRow dense>
          <VCol cols="12"><VTextField v-model="form.item_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_item') + (task.scan_required ? ' *' : '')" autofocus /></VCol>
          <VCol cols="12" md="7"><VSelect v-model="form.bin" :items="bins" item-title="title" item-value="id" :disabled="directedLock" :label="t('warehouse_management.putaway.destination')"
                                          :hint="directedLock ? t('warehouse_management.putaway.directed_hint') : ''" persistent-hint /></VCol>
          <VCol cols="12" md="5"><VTextField v-model="form.bin_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_bin') + (task.scan_required ? ' *' : '')" /></VCol>
          <VCol cols="12" md="5"><VTextField v-model.number="form.qty" type="number" min="0" :max="remaining" :label="t('warehouse_management.locations.qty')" /></VCol>
        </VRow>
      </VCardText>
      <VCardActions class="flex-wrap">
        <VBtn color="primary" size="large" :loading="form.processing" :disabled="!form.bin" @click="complete">{{ t('warehouse_management.putaway.confirm') }}</VBtn>
        <VSpacer />
        <VBtn variant="text" @click="act('resuggest')">{{ t('warehouse_management.putaway.resuggest') }}</VBtn>
        <VBtn variant="text" color="error" @click="act('fail')">{{ t('warehouse_management.putaway.cannot') }}</VBtn>
        <VBtn variant="text" @click="act('cancel')">{{ t('warehouse_management.common.cancel') }}</VBtn>
      </VCardActions>
    </VCard>
    <VAlert v-else-if="task.exception_note" type="warning" variant="tonal">{{ task.exception_note }}</VAlert>

    <VDialog :model-value="!!reasonDialog" max-width="420" @update:model-value="v => { if (!v) reasonDialog = null }">
      <VCard>
        <VCardTitle>{{ reasonDialog === 'fail' ? t('warehouse_management.putaway.cannot') : t('warehouse_management.common.cancel') }}</VCardTitle>
        <VCardText><VTextarea v-model="form.reason" rows="2" auto-grow :label="t('warehouse_management.common.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="reasonDialog = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="error" :disabled="!form.reason" @click="act(reasonDialog!)">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
