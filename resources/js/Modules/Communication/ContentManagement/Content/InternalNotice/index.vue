<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Content, CompanyUnit } from '../utils/type'
import { ContentType } from '../utils/type'
import { useInternalNoticeStore } from './store'
import ContentIndex from '../Shared/Contentindex.vue'

interface CompanyUnitsObject {
  divisions?: CompanyUnit[]
  departments?: CompanyUnit[]
  teams?: CompanyUnit[]
  sections?: CompanyUnit[]
  units?: CompanyUnit[]
}

const props = defineProps<{
  notices: Content[]
  companyUnits: CompanyUnitsObject
  reactionsSummary?: Record<string, any>
}>()

const store = useInternalNoticeStore()

watch(() => props.notices, (val) => store.setList(val), { immediate: true })

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
    :content-type="ContentType.InternalNotice"
    content-label="Internal Notices"
    content-icon="fa-solid fa-shield-halved"
    add-title="Add Internal Notice"
    add-subtitle="Create a new internal notice"
    add-icon="fa-solid fa-shield-halved"
    edit-title="Edit Internal Notice"
    edit-subtitle="Modify the internal notice"
    edit-icon="fa-solid fa-pen-to-square"
    empty-icon="fa-regular fa-file-lines"
    :company-units="flatCompanyUnits"
    :reactions-summary="reactionsSummary"
  />
</template>
