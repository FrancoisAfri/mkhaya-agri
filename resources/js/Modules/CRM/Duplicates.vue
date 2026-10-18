<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface DupRecord { id: number; label: string; email: string | null; created: string | null }
interface Cluster { key: string; records: DupRecord[] }

const props = defineProps<{ entityType: string; entityTypes: string[]; clusters: Cluster[]; counts: Record<string, number> }>()

const tab = ref(props.entityType)
const primaryChoice = ref<Record<string, number>>({})

function switchType(tp: string) { tab.value = tp; router.get(route('crm.duplicates'), { entity_type: tp }, { preserveState: false }) }
function merge(cluster: Cluster, duplicateId: number) {
  const primaryId = primaryChoice.value[cluster.key] ?? cluster.records[0].id
  if (primaryId === duplicateId) return
  useForm({ entity_type: props.entityType, primary_id: primaryId, duplicate_id: duplicateId }).post(route('crm.duplicates.merge'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <h5 class="text-h5 font-weight-semibold mb-1">{{ t('crm_duplicates_index.page_title') }}</h5>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ t('crm_duplicates_index.description') }}</p>

    <VTabs v-model="tab" class="mb-4" @update:model-value="(tp: any) => switchType(String(tp))">
      <VTab v-for="tp in entityTypes" :key="tp" :value="tp" class="text-capitalize">{{ tp }}s<VChip v-if="counts[tp]" size="x-small" color="warning" label class="ml-2">{{ counts[tp] }}</VChip></VTab>
    </VTabs>

    <VAlert v-if="!clusters.length" type="success" variant="tonal" icon="tabler-circle-check">{{ t('crm_duplicates_index.no_duplicates', { entity: entityType }) }}</VAlert>

    <VCard v-for="cluster in clusters" :key="cluster.key" class="mb-3" border elevation="0"><VCardText>
      <div class="text-overline mb-2">{{ t('crm_duplicates_index.match') }}: <span class="text-primary">{{ cluster.key }}</span></div>
      <VTable density="comfortable">
        <thead><tr><th style="width:48px">{{ t('crm_duplicates_index.table.primary') }}</th><th>{{ t('crm_duplicates_index.table.record') }}</th><th>{{ t('crm_duplicates_index.table.email') }}</th><th>{{ t('crm_duplicates_index.table.created') }}</th><th class="text-right">{{ t('crm_duplicates_index.table.action') }}</th></tr></thead>
        <tbody>
          <tr v-for="rec in cluster.records" :key="rec.id">
            <td><VRadio :model-value="primaryChoice[cluster.key] ?? cluster.records[0].id" :value="rec.id" @update:model-value="primaryChoice[cluster.key] = rec.id" /></td>
            <td class="font-weight-medium">{{ rec.label }} <span class="text-disabled text-caption">#{{ rec.id }}</span></td>
            <td class="text-medium-emphasis">{{ rec.email }}</td>
            <td class="text-medium-emphasis text-caption">{{ rec.created }}</td>
            <td class="text-right"><VBtn v-if="(primaryChoice[cluster.key] ?? cluster.records[0].id) !== rec.id" size="x-small" variant="tonal" color="warning" prepend-icon="tabler-arrows-merge" @click="merge(cluster, rec.id)">{{ t('crm_duplicates_index.merge_into_primary') }}</VBtn>
<VChip v-else size="x-small" color="success" label>
  {{ t('crm_duplicates_index.primary_label') }}
</VChip>
</td>
          </tr>
        </tbody>
      </VTable>
    </VCardText></VCard>
  </div>
</template>
