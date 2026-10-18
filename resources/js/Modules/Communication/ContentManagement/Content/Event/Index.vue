<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Content, CompanyUnit } from '../utils/type'
import { ContentType } from '../utils/type'
import { useEventStore } from './store'
import ContentIndex from '../Shared/Contentindex.vue'

interface CompanyUnitsObject {
  divisions?: CompanyUnit[]
  departments?: CompanyUnit[]
  teams?: CompanyUnit[]
  sections?: CompanyUnit[]
  units?: CompanyUnit[]
}

const props = defineProps<{
  events: Content[]
  companyUnits: CompanyUnitsObject
  reactionsSummary?: Record<string, any>
}>()

const store = useEventStore()

watch(() => props.events, (val) => store.setList(val), { immediate: true })

const flatCompanyUnits = computed<CompanyUnit[]>(() => [
  ...(props.companyUnits?.divisions   ?? []),
  ...(props.companyUnits?.departments ?? []),
  ...(props.companyUnits?.teams       ?? []),
  ...(props.companyUnits?.sections    ?? []),
  ...(props.companyUnits?.units       ?? []),
])
</script>

<template>
  <ContentIndex
    :store="store"
    :content-type="ContentType.Event"
    content-label="Events"
    content-icon="fa-solid fa-calendar-days"
    add-title="Add Event"
    add-subtitle="Create a new event"
    add-icon="fa-solid fa-calendar-plus"
    edit-title="Edit Event"
    edit-subtitle="Modify the event"
    edit-icon="fa-solid fa-pen-to-square"
    empty-icon="fa-regular fa-calendar"
    :company-units="flatCompanyUnits"
    :reactions-summary="reactionsSummary"
  />
</template>
