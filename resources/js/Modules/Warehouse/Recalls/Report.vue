<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import RecallSections from '../components/recall/RecallSections.vue'
import type { RecallPayload } from '../components/recall/types'

const { t } = useI18n()
defineProps<RecallPayload & { printed_at: string }>()
const print = () => window.print()
</script>

<template>
  <div class="pa-6 recall-report">
    <div class="d-flex justify-space-between align-start mb-4">
      <div>
        <div class="text-overline">{{ t('warehouse_management.recalls.report') }}</div>
        <h4 class="text-h4">{{ recall.number }} · {{ recall.title }}</h4>
        <p class="text-body-2 mb-1">{{ t(`warehouse_management.recall_class.${recall.severity}`) }} · {{ t(`warehouse_management.recall_scope.${recall.scope}`) }}<span v-if="recall.item"> · {{ recall.item }}</span></p>
        <p class="text-body-2 mb-1">{{ recall.reason }}</p>
        <p v-if="recall.closure_notes" class="text-body-2 mb-0">{{ t('warehouse_management.recalls.conclusion') }}: {{ recall.closure_notes }}</p>
      </div>
      <div class="text-end text-caption">
        <div>{{ t('warehouse_management.recalls.opened') }}: {{ new Date(recall.opened_at).toLocaleString() }}</div>
        <div v-if="recall.closed_at">{{ t('warehouse_management.recalls.closed_at') }}: {{ new Date(recall.closed_at).toLocaleString() }}</div>
        <div>{{ t('warehouse_management.recalls.printed', { at: new Date(printed_at).toLocaleString() }) }}</div>
        <VBtn class="mt-2 d-print-none" size="small" prepend-icon="tabler-printer" @click="print">{{ t('warehouse_management.recalls.print') }}</VBtn>
      </div>
    </div>
    <RecallSections :recall="recall" :lots="lots" :actions="actions" :impact="impact" :evidence="evidence" printing />
  </div>
</template>
