<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getJSON } from '../api'

type Audit = {
  id: number
  scope: string
  station_slug: string | null
  station_name: string | null
  action: string
  old_value: number | null
  new_value: number | null
  old_source: string
  new_source: string
  created_at: string
}
type Station = { slug: string; name: string }

const ACTION_LABELS: Record<string, string> = {
  set_override: '写入站覆盖',
  clear_override: '清空覆盖',
  set_global: '修改全局默认',
}
const SOURCE_LABELS: Record<string, string> = { global: '全局默认', override: '站级覆盖' }

const route = useRoute()
const items = ref<Audit[]>([])
const stations = ref<Station[]>([])
const station = ref(String(route.query.station ?? ''))
const err = ref('')

function fmtTime(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}
function fmtVal(v: number | null): string {
  return v === null ? '—' : `${v} m`
}
function objectOf(a: Audit): string {
  if (a.scope === 'global') return '全局默认'
  return a.station_name ? `${a.station_name}（${a.station_slug}）` : String(a.station_slug ?? '')
}

async function load() {
  const q = station.value ? `?station=${encodeURIComponent(station.value)}` : ''
  items.value = (await getJSON<{ items: Audit[] }>(`/api/threshold-audits${q}`)).items
}

onMounted(async () => {
  try {
    stations.value = (await getJSON<{ items: Station[] }>('/api/stations')).items
    await load()
  } catch (e) {
    err.value = String(e)
  }
})
watch(station, () => load().catch((e) => (err.value = String(e))))
</script>
<template>
  <div class="page">
    <h1>阈值变更审计</h1>
    <p class="lead">写入站覆盖、清空覆盖、修改全局默认都会在此留痕。</p>
    <p v-if="err" class="err">{{ err }}</p>
    <div class="panel" style="margin-bottom: 12px">
      <label>按对象过滤
        <select v-model="station">
          <option value="">全部</option>
          <option value="global">仅全局默认</option>
          <option v-for="s in stations" :key="s.slug" :value="s.slug">{{ s.name }}</option>
        </select>
      </label>
    </div>
    <div class="panel">
      <table>
        <thead>
          <tr><th>时间</th><th>对象</th><th>动作</th><th>旧值</th><th>新值</th><th>来源切换</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in items" :key="a.id">
            <td>{{ fmtTime(a.created_at) }}</td>
            <td>{{ objectOf(a) }}</td>
            <td>{{ ACTION_LABELS[a.action] ?? a.action }}</td>
            <td>{{ fmtVal(a.old_value) }}</td>
            <td>{{ fmtVal(a.new_value) }}</td>
            <td>{{ SOURCE_LABELS[a.old_source] ?? a.old_source }} → {{ SOURCE_LABELS[a.new_source] ?? a.new_source }}</td>
          </tr>
          <tr v-if="!items.length"><td colspan="6">暂无审计记录</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
