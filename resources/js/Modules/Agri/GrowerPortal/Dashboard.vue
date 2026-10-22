<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { money, num } from '../Components/agri'

defineOptions({ layout: null })
defineProps<{
  brand: Brand; me: Me
  summary: { advanced: number; recovered: number; advances_outstanding: number; collected_value: number; to_pay: number }
  visits: Array<{ id: string; date: string; purpose: string; contract: string | null }>
  notices: Array<{ id: string; title: string; date: string }>
  deliveries: Array<{ id: string; date: string; accepted: number; unit: string | null; grade: string | null; value: number; lot: string | null }>
  contracts: Array<{ id: string; reference: string; product: string; expected_quantity: number; delivered: number; unit: string }>
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="k('hello', { name: me.grower ?? me.name })">
    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">{{ k('delivered_value') }}</div><div class="text-h6">{{ money(summary.collected_value) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">{{ k('advances_outstanding') }}</div><div class="text-h6">{{ money(summary.advances_outstanding) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">{{ k('to_pay') }}</div><div class="text-h6">{{ money(summary.to_pay) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">{{ k('active_contracts') }}</div><div class="text-h6">{{ contracts.length }}</div></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="6">
        <VCard :title="k('my_contracts')">
          <VList density="compact">
            <VListItem v-for="c in contracts" :key="c.id" :href="route('grower.portal.contracts.show', c.id)">
              <VListItemTitle>{{ c.reference }} · {{ c.product }}</VListItemTitle>
              <VListItemSubtitle>{{ k('delivered_of', { done: num(c.delivered), expected: num(c.expected_quantity), unit: c.unit }) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!contracts.length"><VListItemSubtitle>{{ k('no_contracts') }}</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="k('upcoming_visits')">
          <VList density="compact">
            <VListItem v-for="v in visits" :key="v.id">
              <VListItemTitle>{{ v.date }} · {{ k('purpose_' + v.purpose) }}</VListItemTitle>
              <VListItemSubtitle v-if="v.contract">{{ v.contract }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!visits.length"><VListItemSubtitle>{{ k('no_visits') }}</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="k('recent_deliveries')">
          <VList density="compact">
            <VListItem v-for="d in deliveries" :key="d.id">
              <VListItemTitle>{{ d.date }} · {{ num(d.accepted) }} {{ d.unit }} {{ d.grade ?? '' }}</VListItemTitle>
              <VListItemSubtitle>{{ d.lot ?? '' }} · {{ money(d.value) }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!deliveries.length"><VListItemSubtitle>{{ k('no_deliveries') }}</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="k('messages')">
          <VList density="compact">
            <VListItem v-for="n in notices" :key="n.id" :href="route('grower.portal.messages')">
              <VListItemTitle>{{ n.title }}</VListItemTitle><VListItemSubtitle>{{ n.date }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!notices.length"><VListItemSubtitle>{{ k('no_messages') }}</VListItemSubtitle></VListItem>
          </VList>
          <VCardActions><Link :href="route('grower.portal.messages')" class="text-primary text-body-2 px-2">{{ k('report_or_issue') }}</Link></VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </GrowerShell>
</template>
