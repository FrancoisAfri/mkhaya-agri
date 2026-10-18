<script setup lang="ts">
import { computed, watch }   from 'vue'
import { useDownloadsStore } from './Store'
import ResourceIndex         from '../Shared/ResourceIndex.vue'
import { ResourceType, type Resource, type GroupedUnits, type CompanyUnit } from '../Store/type'

const props = defineProps<{
  downloads:    Resource[]
  companyUnits: GroupedUnits
}>()

const store = useDownloadsStore()
watch(() => props.downloads, (val) => store.setList(val), { immediate: true })

const flatUnits = computed<CompanyUnit[]>(() => [
  ...(props.companyUnits?.divisions   ?? []),
  ...(props.companyUnits?.departments ?? []),
  ...(props.companyUnits?.teams       ?? []),
  ...(props.companyUnits?.sections    ?? []),
  ...(props.companyUnits?.units       ?? []),
])
</script>

<template>
  <ResourceIndex
    :store="store"
    :resource-type="ResourceType.Download"
    section-label="Downloads"
    :company-units="flatUnits"
  />
</template>
