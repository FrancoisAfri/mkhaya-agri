<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Change { field: string; old: string; new: string }
interface Activity {
  id: number; entity: string; entity_id: number | null; action: string
  by: string; changes: Change[]; at: string | null; at_full: string | null
}

const props = defineProps<{
  activities: Activity[]
  entities: string[]
  actions: string[]
  filters: { entity?: string; action?: string; from?: string; to?: string }
}>()

const entity = ref(props.filters.entity ?? '')
const action = ref(props.filters.action ?? '')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

function apply() {
  router.get(route('crm.audit'), {
    entity: entity.value || undefined, action: action.value || undefined,
    from: from.value || undefined, to: to.value || undefined,
  }, { preserveState: false })
}
function reset() { entity.value = ''; action.value = ''; from.value = ''; to.value = ''; apply() }
function exportCsv() {
  const qs = new URLSearchParams(Object.entries({ entity: entity.value, action: action.value, from: from.value, to: to.value }).filter(([, v]) => v) as any).toString()
  window.location.href = route('crm.audit.export') + (qs ? `?${qs}` : '')
}

const actionColor = (a: string) => a === 'created' ? 'success' : a === 'deleted' ? 'error' : 'primary'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_audit_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_audit_index.description') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-download" @click="exportCsv">{{ t('crm_audit_index.export_csv') }}</VBtn>
    </div>

    <VCard class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VSelect v-model="entity" :label="t('crm_audit_index.filters.entity')" density="compact" hide-details clearable style="max-width: 180px" :items="entities" />
        <VSelect v-model="action" :label="t('crm_audit_index.filters.action')" density="compact" hide-details clearable style="max-width: 160px" :items="actions" />
        <VTextField v-model="from" type="date" :label="t('crm_audit_index.filters.from')" density="compact" hide-details style="max-width: 160px" />
        <VTextField v-model="to" type="date" :label="t('crm_audit_index.filters.to')" density="compact" hide-details style="max-width: 160px" />
        <VBtn variant="tonal" @click="apply">{{ t('crm_audit_index.filters.apply') }}</VBtn>
        <VBtn variant="text" @click="reset">{{ t('crm_audit_index.filters.reset') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard>
      <VList lines="two">
        <template v-for="a in activities" :key="a.id">
          <VListItem>
            <template #prepend>
              <VAvatar :color="actionColor(a.action)" variant="tonal" size="38">
                <VIcon :icon="a.action === 'created' ? 'tabler-plus' : a.action === 'deleted' ? 'tabler-trash' : 'tabler-pencil'" size="18" />
              </VAvatar>
            </template>
            <VListItemTitle class="font-weight-medium">
              <span class="text-capitalize">{{ a.action }}</span> {{ a.entity }} <span class="text-disabled">#{{ a.entity_id }}</span>
            </VListItemTitle>
            <VListItemSubtitle>{{ a.by.trim() || t('crm_audit_index.system') }} · {{ a.at }}</VListItemSubtitle>
            <div v-if="a.changes.length" class="mt-1">
              <div v-for="c in a.changes" :key="c.field" class="text-caption">
                <strong>{{ c.field }}:</strong>
                <span class="text-error text-decoration-line-through">{{ c.old }}</span>
                <VIcon icon="tabler-arrow-right" size="12" class="mx-1" />
                <span class="text-success">{{ c.new }}</span>
              </div>
            </div>
          </VListItem>
          <VDivider />
        </template>
        <VListItem v-if="!activities.length" :title="t('crm_audit_index.no_entries')" />
      </VList>
    </VCard>
  </div>
</template>
