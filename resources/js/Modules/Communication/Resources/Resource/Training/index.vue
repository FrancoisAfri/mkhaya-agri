<script setup lang="ts">
import { computed, watch }  from 'vue'
import { useTrainingStore } from './Store'
import ResourceIndex        from '../Shared/ResourceIndex.vue'
import { ResourceType, type Resource, type GroupedUnits, type CompanyUnit } from '../Store/type'

const props = defineProps<{
  training:     Resource[]
  companyUnits: GroupedUnits
}>()

const store = useTrainingStore()
watch(() => props.training, (val) => store.setList(val), { immediate: true })

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
    :resource-type="ResourceType.Training"
    section-label="Training Materials"
    :company-units="flatUnits"
  />
</template>
