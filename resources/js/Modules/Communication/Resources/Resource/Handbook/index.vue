<script setup lang="ts">
import { computed, watch }  from 'vue'
import { useHandbookStore } from './Store'
import ResourceIndex        from '../Shared/ResourceIndex.vue'
import { ResourceType, type Resource, type GroupedUnits, type CompanyUnit } from '../Store/type'

const props = defineProps<{
  handbooks:    Resource[]
  companyUnits: GroupedUnits
}>()

const store = useHandbookStore()
watch(() => props.handbooks, (val) => store.setList(val), { immediate: true })

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
    :resource-type="ResourceType.Handbook"
    section-label="Employee Handbook"
    :company-units="flatUnits"
  />
</template>
