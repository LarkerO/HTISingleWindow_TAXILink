<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, report, type Point } from '../api';
import { selectPoint } from '../booking';
const route = useRoute(),
  router = useRouter(),
  query = ref(''),
  items = ref<Point[]>([]),
  groups = ref<{ id: string; name: string }[]>([]),
  loading = ref(true);
onMounted(async () => {
  try {
    const d = await api<{ items: Point[]; groups: { id: string; name: string }[] }>('/points');
    items.value = d.items;
    groups.value = d.groups;
  } catch (e) {
    report(e);
  } finally {
    loading.value = false;
  }
});
const filtered = computed(() =>
  items.value.filter((p) =>
    (
      p.name +
      ' ' +
      p.description +
      ' ' +
      (groups.value.find((g) => g.id === p.group_id)?.name || '')
    ).includes(query.value.trim()),
  ),
);
function pick(p: Point) {
  selectPoint(String(route.params.which), p);
  router.push('/');
}
</script>
<template>
  <section class="page-head">
    <button class="back" @click="router.back()">←</button>
    <div>
      <div class="eyebrow">CHOOSE YOUR PLACE</div>
      <h1>选择{{ route.params.which === 'dropoff' ? '下车' : '上车' }}点</h1>
    </div>
  </section>
  <t-search v-model="query" placeholder="搜索地点、车站或点组" />
  <p class="helper">选择具体出入口，让接送更准确</p>
  <t-loading v-if="loading" text="正在加载地点" /><template v-else
    ><section
      v-for="group in [{ id: null, name: '城市地点' }, ...groups]"
      :key="group.id || 'ungrouped'"
      class="point-section"
    >
      <h2 v-if="filtered.some((p) => p.group_id === group.id)">
        {{ group.name }}<small v-if="group.id">请选择下属地点</small>
      </h2>
      <button
        v-for="point in filtered.filter((p) => p.group_id === group.id)"
        :key="point.id"
        class="point-item"
        @click="pick(point)"
      >
        <span class="place-icon">⌖</span
        ><span
          ><strong>{{ point.name }}</strong
          ><small>{{ point.description }}</small></span
        ><span class="chevron">↗</span>
      </button>
    </section>
    <div v-if="!filtered.length" class="empty">
      <span>⌕</span>
      <h2>没有找到地点</h2>
      <p>试试其他名称或车站关键词</p>
    </div></template
  >
</template>
