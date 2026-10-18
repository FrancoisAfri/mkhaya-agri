<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; kind: string; message: string; status: string; level: number; order: { id: string; number: string } | null
  operation: string | null; work_centre: string | null; raised_by: string | null; raised_at: string | null
  assigned_to: string | null; resolution: string | null; resolved_at: string | null
}

const props = defineProps<{
  escalations: { data: Row[]; current_page: number; last_page: number }
  filters: { status: string }
  can: { respond: boolean }
}>()

const status = ref(props.filters.status)
watch(status, v => router.get(route('manufacturing.floor.escalations.index'), { status: v }, { preserveState: true, replace: true }))

const busy = ref(false)
const acknowledge = (e: Row) => router.post(route('manufacturing.floor.escalations.acknowledge', e.id), {}, { preserveScroll: true })
const resolving = ref<Row | null>(null)
const resolution = ref('')
function submitResolve() {
  busy.value = true
  router.post(route('manufacturing.floor.escalations.resolve', resolving.value!.id), { resolution: resolution.value }, {
    preserveScroll: true, onSuccess: () => { resolving.value = null; resolution.value = '' }, onFinish: () => { busy.value = false },
  })
}

const ago = (s: string | null) => {
  if (!s) return '—'
  const min = Math.round((Date.now() - new Date(s).getTime()) / 60000)
  return min < 60 ? t('manufacturing.floor.min_ago', { n: min }) : t('manufacturing.floor.hours_ago', { n: Math.round(min / 60) })
}
const statusColor = (s: string) => ({ OPEN: 'error', ACKNOWLEDGED: 'warning', RESOLVED: 'success' } as Record<string, string>)[s] ?? 'default'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.floor.escalations_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.floor.escalations_subtitle') }}</p>
    </div>

    <VBtnToggle v-model="status" mandatory divided variant="outlined" class="mb-4">
      <VBtn value="OPEN_ALL">{{ t('manufacturing.floor.unresolved') }}</VBtn>
      <VBtn value="OPEN">{{ t('manufacturing.floor.esc_status.OPEN') }}</VBtn>
      <VBtn value="ACKNOWLEDGED">{{ t('manufacturing.floor.esc_status.ACKNOWLEDGED') }}</VBtn>
      <VBtn value="RESOLVED">{{ t('manufacturing.floor.esc_status.RESOLVED') }}</VBtn>
    </VBtnToggle>

    <VCard border>
      <VList lines="three">
        <VListItem v-for="e in escalations.data" :key="e.id">
          <template #prepend>
            <VAvatar :color="e.level > 1 ? 'error' : 'warning'" variant="tonal">{{ e.level }}</VAvatar>
          </template>
          <VListItemTitle class="d-flex gap-2 align-center flex-wrap">
            <VChip size="x-small" label>{{ t(`manufacturing.floor.kind.${e.kind}`) }}</VChip>
            <span>{{ e.message }}</span>
          </VListItemTitle>
          <VListItemSubtitle>
            <template v-if="e.order"><a :href="route('manufacturing.production.orders.show', e.order.id)">{{ e.order.number }}</a><span v-if="e.operation"> · {{ e.operation }}</span> · </template>
            <span v-if="e.work_centre">{{ e.work_centre }} · </span>{{ e.raised_by ?? '—' }} · {{ ago(e.raised_at) }}
            <span v-if="e.assigned_to"> · {{ t('manufacturing.floor.assigned_to', { name: e.assigned_to }) }}</span>
            <div v-if="e.resolution" class="text-success">{{ e.resolution }}</div>
          </VListItemSubtitle>
          <template #append>
            <div class="d-flex gap-2 align-center">
              <VChip size="small" label :color="statusColor(e.status)">{{ t(`manufacturing.floor.esc_status.${e.status}`) }}</VChip>
              <template v-if="can.respond && e.status !== 'RESOLVED'">
                <VBtn v-if="e.status === 'OPEN'" size="small" variant="tonal" @click="acknowledge(e)">{{ t('manufacturing.floor.acknowledge') }}</VBtn>
                <VBtn size="small" color="success" variant="tonal" @click="resolving = e">{{ t('manufacturing.floor.resolve') }}</VBtn>
              </template>
            </div>
          </template>
        </VListItem>
        <VListItem v-if="!escalations.data.length" :title="t('manufacturing.floor.no_escalations')" />
      </VList>
      <VCardText v-if="escalations.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="escalations.current_page" :length="escalations.last_page" total-visible="7"
                     @update:model-value="(p: number) => router.get(route('manufacturing.floor.escalations.index'), { status, page: p }, { preserveState: true })" />
      </VCardText>
    </VCard>

    <VDialog :model-value="resolving !== null" max-width="480" @update:model-value="v => { if (!v) resolving = null }">
      <VCard v-if="resolving" :title="t('manufacturing.floor.resolve')" :subtitle="resolving.message">
        <VCardText><VTextarea v-model="resolution" rows="3" auto-grow autofocus :label="t('manufacturing.floor.how_resolved')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="resolving = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" :disabled="!resolution.trim()" @click="submitResolve">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
