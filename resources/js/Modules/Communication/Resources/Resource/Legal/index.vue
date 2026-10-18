<script setup lang="ts">
import { computed, watch } from 'vue'
import { useLegalStore }   from './Store'
import ResourceIndex       from '../Shared/ResourceIndex.vue'
import { ResourceType, type Resource, type GroupedUnits, type CompanyUnit } from '../Store/type'

const props = defineProps<{
  resources:        Resource[]
  companyUnits: GroupedUnits
}>()

const store = useLegalStore()
watch(() => props.resources, (val) => store.setList(val), { immediate: true })

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
    :resource-type="ResourceType.Legal"
    section-label="Legal Documents"
    :company-units="flatUnits"
  />
</template>
