<script setup lang="ts">
import { computed, watch }      from 'vue'
import { useHealthSafetyStore } from './Store'
import ResourceIndex            from '../Shared/ResourceIndex.vue'
import { ResourceType, type Resource, type GroupedUnits, type CompanyUnit } from '../Store/type'

const props = defineProps<{
  healthSafety: Resource[]
  companyUnits: GroupedUnits
}>()

const store = useHealthSafetyStore()
watch(() => props.healthSafety, (val) => store.setList(val), { immediate: true })

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
    :resource-type="ResourceType.HealthSafety"
    section-label="Health & Safety"
    :company-units="flatUnits"
  />
</template>
