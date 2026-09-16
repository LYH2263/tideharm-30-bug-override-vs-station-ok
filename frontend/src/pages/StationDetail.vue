<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { getJSON, sendJSON } from '../api'

type C = { name: string; speed_deg_per_hour: number; amplitude_m: number; phase_deg: number }
type Station = {
  name: string
  constituents: C[]
  threshold_m: number
  threshold_source: string
  override_m: number | null
  global_threshold_m: number
}
type ThresholdState = { threshold_m: number; source: string; override_m: number | null; global_m: number }

const route = useRoute()
const items = ref<C[]>([])
const name = ref('')
const thr = ref(0)
const thrSource = ref('global')
const globalThr = ref(0)
const overrideVal = ref<number | null>(null)
const thrInput = ref(0)
const msg = ref('')
const err = ref('')

function applyThreshold(t: ThresholdState) {
  thr.value = t.threshold_m
  thrSource.value = t.source
  overrideVal.value = t.override_m
  globalThr.value = t.global_m
  thrInput.value = t.override_m ?? t.global_m
}

async function load() {
  const slug = String(route.params.slug)
  const st = await getJSON<Station>(`/api/stations/${slug}`)
  name.value = st.name
  items.value = st.constituents
  applyThreshold({
    threshold_m: st.threshold_m,
    source: st.threshold_source,
    override_m: st.override_m,
    global_m: st.global_threshold_m,
  })
}

async function save() {
  err.value = ''
  msg.value = ''
  try {
    const slug = String(route.params.slug)
    await sendJSON(`/api/stations/${slug}/constituents`, 'PUT', { items: items.value })
    msg.value = '已保存'
    await load()
  } catch (e) {
    err.value = String(e)
  }
}

async function saveThreshold() {
  err.value = ''
  msg.value = ''
  try {
    const slug = String(route.params.slug)
    const t = await sendJSON<ThresholdState>(`/api/stations/${slug}/threshold`, 'PUT', { threshold_m: thrInput.value })
    applyThreshold(t)
    msg.value = '站级覆盖已写入，可在审计页核对'
  } catch (e) {
    err.value = String(e)
  }
}

async function clearThreshold() {
  err.value = ''
  msg.value = ''
  try {
    const slug = String(route.params.slug)
    const t = await sendJSON<ThresholdState>(`/api/stations/${slug}/threshold`, 'DELETE')
    applyThreshold(t)
    msg.value = '站级覆盖已清空，可在审计页核对'
  } catch (e) {
    err.value = String(e)
  }
}

onMounted(() => load().catch((e) => (err.value = String(e))))
</script>
<template>
  <div class="page">
    <h1>{{ name }} · 分潮</h1>
    <p class="lead"><RouterLink to="/stations">返回列表</RouterLink> ·
      <RouterLink :to="`/stations/${route.params.slug}/residuals`">残差</RouterLink> ·
      <RouterLink :to="`/audits?station=${route.params.slug}`">阈值审计</RouterLink></p>
    <p v-if="err" class="err">{{ err }}</p>
    <p v-if="msg">{{ msg }}</p>
    <div class="panel" style="margin-bottom: 12px">
      <p class="lead" style="margin-top: 0">
        残差阈值：当前 {{ thr }} m（{{ thrSource === 'override' ? '站级覆盖' : '全局默认' }}），全局默认 {{ globalThr }} m
      </p>
      <label>站级覆盖 <input type="number" step="0.01" v-model.number="thrInput" /></label>
      <button style="margin-left: 8px" @click="saveThreshold">写入覆盖</button>
      <button v-if="overrideVal !== null" style="margin-left: 8px" @click="clearThreshold">清空覆盖</button>
    </div>
    <div class="panel">
      <table>
        <thead><tr><th>名</th><th>角速度°/h</th><th>振幅 m</th><th>迟角 °</th></tr></thead>
        <tbody>
          <tr v-for="(c, i) in items" :key="i">
            <td><input v-model="c.name" /></td>
            <td><input type="number" step="0.001" v-model.number="c.speed_deg_per_hour" /></td>
            <td><input type="number" step="0.01" v-model.number="c.amplitude_m" /></td>
            <td><input type="number" step="0.1" v-model.number="c.phase_deg" /></td>
          </tr>
        </tbody>
      </table>
      <button style="margin-top: 12px" @click="save">保存分潮</button>
    </div>
  </div>
</template>
