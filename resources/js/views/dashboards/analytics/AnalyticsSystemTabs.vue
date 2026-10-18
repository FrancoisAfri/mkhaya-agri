<script setup lang="ts">
import australia from '@images/icons/countries/au.png'
import brazil from '@images/icons/countries/br.png'
import china from '@images/icons/countries/cn.png'
import france from '@images/icons/countries/fr.png'
import india from '@images/icons/countries/in.png'
import usa from '@images/icons/countries/us.png'
import brave from '@images/logos/brave.png'
import cent from '@images/logos/cent.png'
import chrome from '@images/logos/chrome.png'
import edge from '@images/logos/edge.png'
import firefox from '@images/logos/firefox.png'
import mac from '@images/logos/mac.png'
import opera from '@images/logos/opera-mini.png'
import safari from '@images/logos/safari.png'
import ubuntu from '@images/logos/ubuntu.png'
import ucBrowser from '@images/logos/uc-browser.png'
import windows from '@images/logos/windows.png'

type Tabs = 'browser' | 'system' | 'country'

const tabs = ref<Tabs>('browser')

interface TableContent {
  id: number
  visits: string | number
  color: string
  browser?: string
  system?: string
  country?: string
  percentage: number
  src: string
}

const browserData: TableContent[] = [
  {
    id: 1,
    visits: '8.92k',
    color: 'success',
    browser: 'Chrome',
    percentage: 64.91,
    src: chrome,
  },
  {
    id: 2,
    visits: '1.29k',
    color: 'primary',
    browser: 'Safari',
    percentage: 19.03,
    src: safari,
  },
  {
    id: 3,
    visits: 328,
    color: 'info',
    percentage: 32.6,
    browser: 'Firefox',
    src: firefox,
  },
  {
    id: 4,
    visits: 142,
    browser: 'Edge',
    color: 'warning',
    percentage: 39.9,
    src: edge,
  },
  {
    id: 5,
    visits: 85,
    color: 'error',
    browser: 'Opera',
    percentage: 21.2,
    src: opera,
  },
  {
    id: 6,
    visits: 328,
    color: 'error',
    browser: 'UC Browser',
    percentage: 20.4,
    src: ucBrowser,
  },
]

const osData: TableContent[] = [
  {
    id: 1,
    color: 'success',
    percentage: 61.5,
    visits: '475.26k',
    system: 'Windows',
    src: windows,
  },
  {
    id: 2,
    system: 'Mac',
    color: 'primary',
    visits: '89.12k',
    percentage: 15.67,
    src: mac,
  },
  {
    id: 3,
    color: 'info',
    visits: '38.68k',
    system: 'Ubuntu',
    percentage: 58.2,
    src: ubuntu,
  },
  {
    id: 4,
    visits: '8.34k',
    color: 'warning',
    system: 'Chrome',
    percentage: 32.5,
    src: chrome,
  },
  {
    id: 5,
    color: 'error',
    system: 'Cent',
    visits: '2.25k',
    percentage: 17.6,
    src: cent,
  },
  {
    id: 6,
    color: 'warning',
    system: 'Brave',
    visits: '3.5k',
    percentage: 25.6,
    src: brave,
  },
]

const countryData: TableContent[] = [
  {
    id: 1,
    country: 'USA',
    color: 'success',
    visits: '87.24k',
    percentage: 38.12,
    src: usa,
  },
  {
    id: 2,
    color: 'primary',
    visits: '42.69k',
    country: 'Brazil',
    percentage: 28.23,
    src: brazil,
  },
  {
    id: 3,
    color: 'info',
    country: 'India',
    visits: '12.58k',
    percentage: 13.82,
    src: india,
  },
  {
    id: 4,
    visits: '4.13k',
    color: 'warning',
    percentage: 12.72,
    country: 'Australia',
    src: australia,
  },
  {
    id: 5,
    color: 'error',
    visits: '2.21k',
    country: 'China',
    percentage: 11.11,
    src: china,
  },
  {
    id: 6,
    color: 'primary',
    visits: '3.78k',
    country: 'France',
    percentage: 10.38,
    src: france,
  },
]

const tableData = computed(() => {
  return tabs.value === 'browser' ? browserData : tabs.value === 'system' ? osData : countryData
})
</script>

<template>
  <VCard>
    <VCardText>
      <VTabs
        v-model="tabs"
        class="v-tabs-pill"
      >
        <VTab value="browser">
          Browser
        </VTab>
        <VTab value="system">
          Operating System
        </VTab>
        <VTab value="country">
          Country
        </VTab>
      </VTabs>
    </VCardText>

    <VDivider />

    <VTable class="text-no-wrap px-1">
      <thead>
        <tr class="text-uppercase text-medium-emphasis">
          <th
            scope="col"
            class="border-0"
          >
            NO
          </th>
          <th
            scope="col"
            class="border-0"
          >
            {{ tabs }}
          </th>
          <th
            scope="col"
            class="border-0"
          >
            VISITS
          </th>
          <th
            scope="col"
            class="border-0 text-center"
          >
            DATA IN PERCENTAGE
          </th>
        </tr>
      </thead>

      <tbody>
        <tr
          v-for="(data, index) in tableData"
          :key="index"
        >
          <td class="border-0 text-high-emphasis">
            {{ index + 1 }}
          </td>
          <td class="border-0">
            <div class="d-flex align-center gap-3">
              <div>
                <VImg
                  :src="data.src"
                  width="24"
                  height="24"
                />
              </div>

              <span class="text-base text-high-emphasis">{{ data[tabs] }}</span>
            </div>
          </td>
          <td class="border-0">
            <span class="text-base text-high-emphasis">{{ data.visits }}</span>
          </td>
          <td class="text-center border-0">
            <div class="d-flex align-center gap-4">
              <div class="flex-grow-1">
                <VProgressLinear
                  rounded
                  rounded-bar
                  height="8"
                  :model-value="data.percentage"
                  :color="data.color"
                />
              </div>
              <span class="text-body-2 font-weight-medium">{{ data.percentage }}%</span>
            </div>
          </td>
        </tr>
      </tbody>
    </VTable>
  </VCard>
</template>
