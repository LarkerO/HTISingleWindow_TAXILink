<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Geometry } from '../api';
const props = defineProps<{
  geometry?: Geometry;
  position?: { x: number; z: number };
  home?: boolean;
}>();
// The viewport operates on projected world X/Z coordinates; a future tile adapter can share this projection.
const scale = ref(1),
  pan = ref({ x: 0, y: 0 }),
  pointers = new Map<number, { x: number; y: number }>();
const source = computed(() =>
  props.geometry?.points?.length
    ? props.geometry.points
    : [
        [0, 80, 64, 300],
        [500, 180, 64, 280],
        [1000, 180, 64, 180],
        [2000, 280, 64, 180],
        [3000, 330, 64, 80],
      ],
);
const bounds = computed(() => {
  const p = source.value;
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
const marker = computed(() => {
  const p = props.position;
  return p ? project(p.x, p.z) : project(source.value[0][1], source.value[0][3]);
});
const start = computed(() => project(source.value[0][1], source.value[0][3]));
const end = computed(() => {
  const p = source.value.at(-1)!;
  return project(p[1], p[3]);
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
    <svg
      viewBox="0 0 400 420"
      role="img"
      aria-label="行驶轨迹地图，可拖动及双指缩放"
      @pointerdown="down"
      @pointermove="move"
      @pointerup="pointers.delete($event.pointerId)"
      @pointercancel="pointers.delete($event.pointerId)"
      @wheel.prevent="zoom($event.deltaY < 0 ? 1.12 : 0.88)"
    >
      <defs>
        <pattern id="streets" width="64" height="64" patternUnits="userSpaceOnUse">
          <rect width="64" height="64" fill="#e8eee7" />
          <rect x="5" y="5" width="48" height="46" rx="7" fill="#f6f7f0" />
          <path d="M0 60H64 M60 0V64" stroke="#fff" stroke-width="5" />
        </pattern>
      </defs>
      <rect width="400" height="420" fill="url(#streets)" />
      <g
        :transform="`translate(${200 + pan.x},${210 + pan.y}) scale(${scale}) translate(-200,-210)`"
      >
        <path
          d="M-100 330 C80 260 40 420 240 330 S380 290 500 380"
          stroke="#b6d5d0"
          stroke-width="52"
          fill="none"
        />
        <path
          d="M-100 330 C80 260 40 420 240 330 S380 290 500 380"
          stroke="#c4ded9"
          stroke-width="40"
          fill="none"
        />
        <rect x="225" y="30" width="100" height="75" rx="22" fill="#cadac0" />
        <text x="240" y="72" fill="#718267" font-size="11">城市绿地</text>
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
        <circle :cx="start.x" :cy="start.y" r="7" fill="#236c58" stroke="white" stroke-width="3" />
        <circle :cx="end.x" :cy="end.y" r="7" fill="#d19c59" stroke="white" stroke-width="3" />
        <circle v-if="!home" :cx="marker.x" :cy="marker.y" r="17" fill="#236c58" opacity=".14" />
        <circle
          v-if="!home"
          :cx="marker.x"
          :cy="marker.y"
          r="7"
          fill="#236c58"
          stroke="white"
          stroke-width="3"
        />
      </g>
    </svg>
    <div class="map-caption">
      {{ geometry ? '轨道行驶轨迹' : '城市轨道 · 示意图' }}<span>双指缩放 · 拖动查看</span>
    </div>
    <div class="map-controls">
      <button aria-label="放大地图" @click="zoom(1.4)">＋</button
      ><button aria-label="缩小地图" @click="zoom(0.7)">−</button
      ><button aria-label="回原点" @click="reset">⌖</button
      ><button v-if="!home" aria-label="定位车辆" @click="follow">◎</button>
    </div>
  </div>
</template>
