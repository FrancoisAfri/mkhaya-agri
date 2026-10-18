<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Content, CompanyUnit } from '../utils/type'
import {ContentType} from '../utils/type'
import { useAnnouncementStore } from './store'
import ContentIndex from '../Shared/Contentindex.vue'

interface CompanyUnitsObject {
  divisions?: CompanyUnit[]
  departments?: CompanyUnit[]
  teams?: CompanyUnit[]
  sections?: CompanyUnit[]
  units?: CompanyUnit[]
}

const props = defineProps<{
  announcements: Content[]
  companyUnits: CompanyUnitsObject
  reactionsSummary?: Record<string, any>
}>()

const store = useAnnouncementStore()

watch(() => props.announcements, (val) => store.setList(val), { immediate: true })

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
    :content-type="ContentType.Announcement"
    content-label="Announcements"
    content-icon="fa-solid fa-bullhorn"
    add-title="Add Announcement"
    add-subtitle="Create a new announcement"
    add-icon="fa-solid fa-bullhorn"
    edit-title="Edit Announcement"
    edit-subtitle="Modify the announcement"
    edit-icon="fa-solid fa-pen-to-square"
    empty-icon="fa-regular fa-bullhorn"
    :company-units="flatCompanyUnits"
    :reactions-summary="reactionsSummary"
  />
</template>
