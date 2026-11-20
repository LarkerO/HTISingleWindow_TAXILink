<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
  blueMap3dUrl,
  blueMapTiles,
  loadBlueMap,
  type BlueMapSettings,
} from '../../../shared/bluemap';
import type { Geometry } from '../api';
import { blueMapHealthScript } from '../../../shared/bluemap-health';
const props = defineProps<{
  geometry?: Geometry;
  position?: { x: number; z: number };
  home?: boolean;
  hideVehicle?: boolean;
}>();
const scale = ref(1),
  pan = ref({ x: 0, y: 0 }),
  pointers = new Map<number, { x: number; y: number }>();
const view3d = ref(false);
const view3dUrl = ref('');
const frame3d = ref<HTMLIFrameElement>();
const mapNotice = ref('');
let healthTimer: ReturnType<typeof setInterval> | undefined;
let healthDeadline: ReturnType<typeof setTimeout> | undefined;
function stopHealth() {
  clearInterval(healthTimer);
  clearTimeout(healthDeadline);
}
function fallback3d() {
  stopHealth();
  view3d.value = false;
  mapNotice.value = '3D 地图暂不可用，已恢复 2D；请检查浏览器 WebGL2 支持或稍后重试。';
}
function checkHealth() {
  try {
    (frame3d.value?.contentWindow as (Window & { eval: (code: string) => void }) | null)?.eval(
      blueMapHealthScript(),
    );
  } catch {
    /* Navigation and module initialization may still be pending. */
  }
}
function receiveHealth(event: MessageEvent) {
  if (
    event.origin !== location.origin ||
    event.source !== frame3d.value?.contentWindow ||
    event.data?.type !== 'taxilink-bluemap-health'
  )
    return;
  if (event.data.status === 'failed') fallback3d();
  if (event.data.status === 'ready') clearTimeout(healthDeadline);
}
const settings = ref<BlueMapSettings>();
const failed = ref(false);
const request = new AbortController();
onMounted(async () => {
  window.addEventListener('message', receiveHealth);
  try {
    settings.value = await loadBlueMap(request.signal);
  } catch {
    if (!request.signal.aborted) failed.value = true;
  }
});
onUnmounted(() => {
  request.abort();
  stopHealth();
  window.removeEventListener('message', receiveHealth);
});
const source = computed(() => props.geometry?.points ?? []);
const bounds = computed(() => {
  const [x, z] = settings.value?.startPos ?? [0, 0];
  const p = source.value;
  if (!p.length) return { x: x - 300, z: z - 300, w: 600, h: 600 };
  const xs = p.map((v) => v[1]),
    zs = p.map((v) => v[3]);
  return {
    x: Math.min(...xs),
    z: Math.min(...zs),
    w: Math.max(100, Math.max(...xs) - Math.min(...xs)),
    h: Math.max(100, Math.max(...zs) - Math.min(...zs)),
  };
});
function project(x: number, z: number) {
  const b = bounds.value;
  const fit = Math.min(300 / b.w, 280 / b.h);
  return { x: 200 + (x - b.x - b.w / 2) * fit, y: 210 + (z - b.z - b.h / 2) * fit };
}
const path = computed(() =>
  source.value
    .map((p, i) => {
      const q = project(p[1], p[3]);
      return `${i ? 'L' : 'M'}${q.x},${q.y}`;
    })
    .join(' '),
);
const tiles = computed(() => {
  if (!settings.value) return [];
  const b = bounds.value;
  const fit = Math.min(300 / b.w, 280 / b.h);
  const pixelsPerBlock = fit * scale.value;
  const x = b.x + b.w / 2 - (200 + pan.value.x) / pixelsPerBlock;
  const z = b.z + b.h / 2 - (210 + pan.value.y) / pixelsPerBlock;
  return blueMapTiles(
    settings.value,
    x,
    z,
    400 / pixelsPerBlock,
    420 / pixelsPerBlock,
    pixelsPerBlock,
  );
});
const marker = computed(() => {
  const p = props.position;
  return p ? project(p.x, p.z) : start.value;
});
const start = computed(() => {
  const p = source.value[0];
  return p ? project(p[1], p[3]) : { x: 200, y: 210 };
});
const end = computed(() => {
  const p = source.value.at(-1)!;
  return p ? project(p[1], p[3]) : start.value;
});
function pointer(e: PointerEvent) {
  const box = (e.currentTarget as SVGElement).getBoundingClientRect();
  return {
    x: ((e.clientX - box.left) * 400) / box.width,
    y: ((e.clientY - box.top) * 420) / box.height,
  };
}
function down(e: PointerEvent) {
  (e.currentTarget as Element).setPointerCapture(e.pointerId);
  pointers.set(e.pointerId, pointer(e));
}
function zoom(factor: number, center = { x: 200, y: 210 }) {
  const next = Math.min(8, Math.max(0.5, scale.value * factor)),
    ratio = next / scale.value;
  pan.value = {
    x: center.x - 200 - (center.x - 200 - pan.value.x) * ratio,
    y: center.y - 210 - (center.y - 210 - pan.value.y) * ratio,
  };
  scale.value = next;
}
function move(e: PointerEvent) {
  if (!pointers.has(e.pointerId)) return;
  const old = [...pointers.values()];
  const previous = pointers.get(e.pointerId)!;
  const next = pointer(e);
  pointers.set(e.pointerId, next);
  const now = [...pointers.values()];
  if (now.length === 1) {
    pan.value = { x: pan.value.x + next.x - previous.x, y: pan.value.y + next.y - previous.y };
  } else {
    const d = (a: typeof old) => Math.hypot(a[0].x - a[1].x, a[0].y - a[1].y);
    const center = { x: (now[0].x + now[1].x) / 2, y: (now[0].y + now[1].y) / 2 };
    const before = { x: (old[0].x + old[1].x) / 2, y: (old[0].y + old[1].y) / 2 };
    pan.value = { x: pan.value.x + center.x - before.x, y: pan.value.y + center.y - before.y };
    if (d(old) > 1) zoom(d(now) / d(old), center);
  }
}
function toggleView() {
  stopHealth();
  mapNotice.value = '';
  if (!view3d.value && settings.value) {
    const b = bounds.value;
    view3dUrl.value = blueMap3dUrl(
      settings.value,
      b.x + b.w / 2,
      b.z + b.h / 2,
      Math.max(b.w, b.h),
    );
    healthTimer = setInterval(checkHealth, 500);
    healthDeadline = setTimeout(fallback3d, 22000);
  }
  view3d.value = !view3d.value;
}
function reset() {
  scale.value = 1;
  pan.value = { x: 0, y: 0 };
}
function follow() {
  scale.value = 2;
  pan.value = { x: (200 - marker.value.x) * 2, y: (210 - marker.value.y) * 2 };
}
</script>
<template>
  <div class="route-map" :class="{ home }">
    <iframe
      v-if="view3d && settings"
      ref="frame3d"
      @load="checkHealth"
      @error="fallback3d"
      title="BlueMap 3D 地图"
      :src="view3dUrl"
      style="position: absolute; inset: 0; width: 100%; height: 100%; border: 0"
    />
    <svg
      v-else
      viewBox="0 0 400 420"
      role="img"
      aria-label="行驶轨迹地图，可拖动及双指缩放"
      @pointerdown="down"
      @pointermove="move"
      @pointerup="pointers.delete($event.pointerId)"
      @pointercancel="pointers.delete($event.pointerId)"
      @wheel.prevent="zoom($event.deltaY < 0 ? 1.12 : 0.88)"
    >
      <rect width="400" height="420" fill="#e8eee7" />
      <g
        :transform="`translate(${200 + pan.x},${210 + pan.y}) scale(${scale}) translate(-200,-210)`"
      >
        <image
          v-for="tile in tiles"
          :key="tile.url"
          :href="tile.url"
          :x="project(tile.x, tile.z).x"
          :y="project(tile.x, tile.z).y"
          :width="project(tile.x + tile.width, tile.z).x - project(tile.x, tile.z).x"
          :height="project(tile.x, tile.z + tile.height).y - project(tile.x, tile.z).y"
          preserveAspectRatio="none"
        />
        <path
          :d="path"
          fill="none"
          stroke="white"
          stroke-width="10"
          stroke-linejoin="round"
          stroke-linecap="round"
        />
        <path
          :d="path"
          fill="none"
          stroke="#236c58"
          stroke-width="5"
          stroke-linejoin="round"
          stroke-linecap="round"
        />
        <circle
          v-if="source.length"
          :cx="start.x"
          :cy="start.y"
          r="7"
          fill="#236c58"
          stroke="white"
          stroke-width="3"
        />
        <circle
          v-if="source.length"
          :cx="end.x"
          :cy="end.y"
          r="7"
          fill="#d19c59"
          stroke="white"
          stroke-width="3"
        />
        <circle
          v-if="!home && !hideVehicle && position"
          :cx="marker.x"
          :cy="marker.y"
          r="17"
          fill="#236c58"
          opacity=".14"
        />
        <circle
          v-if="!home && !hideVehicle && position"
          :cx="marker.x"
          :cy="marker.y"
          r="7"
          fill="#236c58"
          stroke="white"
          stroke-width="3"
        />
      </g>
    </svg>
    <div v-if="!view3d" class="map-caption">
      {{ failed ? 'BlueMap 底图暂不可用' : settings ? 'BlueMap' : '正在加载底图'
      }}<span>双指缩放 · 拖动查看</span>
    </div>
    <div v-if="mapNotice" class="map-recovery-note" role="status">{{ mapNotice }}</div>
    <div class="map-controls">
      <button
        v-if="settings?.allow3d"
        :aria-label="view3d ? '切换到 2D' : '切换到 3D'"
        @click="toggleView"
      >
        {{ view3d ? '2D' : '3D' }}
      </button>
      <template v-if="!view3d">
        <button aria-label="放大地图" @click="zoom(1.4)">＋</button
        ><button aria-label="缩小地图" @click="zoom(0.7)">−</button
        ><button aria-label="回原点" @click="reset">⌖</button
        ><button v-if="!home && !hideVehicle && position" aria-label="定位车辆" @click="follow">
          ◎
        </button>
      </template>
    </div>
  </div>
</template>
