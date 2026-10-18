<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Side = { id: string; revision: number; status: string; product_code: string | null; product_name: string | null }
type Line = {
  component: number; item_code: string; quantity_per: number; scrap_percent: number
  uom: string | null; line_kind: string; is_phantom: boolean; is_optional: boolean
}

defineProps<{
  left: Side
  right: Side
  diff: {
    added: Line[]
    removed: Line[]
    changed: Array<{ component: number; item_code: string; differences: Record<string, { from: unknown; to: unknown }> }>
    unchanged: number
  }
}>()

const show = (v: unknown) => (v === null || v === undefined ? '—' : String(v))
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
          :href="route('manufacturing.engineering.boms.show', left.id)">
      {{ t('manufacturing.explode.back') }}
    </VBtn>
    <h4 class="text-h4">{{ t('manufacturing.compare.title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">
      {{ left.product_code }} — {{ t('manufacturing.bom.revision_n', { n: left.revision }) }}
      → {{ t('manufacturing.bom.revision_n', { n: right.revision }) }}
    </p>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.compare.added') }}</div>
        <div class="text-h5 text-success">{{ diff.added.length }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.compare.removed') }}</div>
        <div class="text-h5 text-error">{{ diff.removed.length }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.compare.changed') }}</div>
        <div class="text-h5 text-warning">{{ diff.changed.length }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">{{ t('manufacturing.compare.unchanged') }}</div>
        <div class="text-h5">{{ diff.unchanged }}</div>
      </VCardText></VCard></VCol>
    </VRow>

    <VAlert v-if="!diff.added.length && !diff.removed.length && !diff.changed.length" type="info" variant="tonal">
      {{ t('manufacturing.compare.identical') }}
    </VAlert>

    <VCard v-if="diff.added.length" border class="mb-4">
      <VCardItem><VCardTitle class="text-success">{{ t('manufacturing.compare.added') }}</VCardTitle></VCardItem>
      <VList density="compact">
        <VListItem v-for="l in diff.added" :key="l.component">
          <VListItemTitle>{{ l.item_code }}</VListItemTitle>
          <VListItemSubtitle>{{ l.quantity_per }} {{ l.uom }} · {{ l.scrap_percent }}%</VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <VCard v-if="diff.removed.length" border class="mb-4">
      <VCardItem><VCardTitle class="text-error">{{ t('manufacturing.compare.removed') }}</VCardTitle></VCardItem>
      <VList density="compact">
        <VListItem v-for="l in diff.removed" :key="l.component">
          <VListItemTitle>{{ l.item_code }}</VListItemTitle>
          <VListItemSubtitle>{{ l.quantity_per }} {{ l.uom }} · {{ l.scrap_percent }}%</VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <VCard v-if="diff.changed.length" border>
      <VCardItem><VCardTitle class="text-warning">{{ t('manufacturing.compare.changed') }}</VCardTitle></VCardItem>
      <VList density="compact">
        <VListItem v-for="c in diff.changed" :key="c.component">
          <VListItemTitle>{{ c.item_code }}</VListItemTitle>
          <VListItemSubtitle>
            <span v-for="(d, field) in c.differences" :key="field" class="me-4">
              {{ t(`manufacturing.compare.field_${field}`) }}: {{ show(d.from) }} → <strong>{{ show(d.to) }}</strong>
            </span>
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
