<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Carousel } from 'ant-design-vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
type Item = {
  title: string;
  image: string;
  enabled: boolean;
  placement: string;
  width: number;
  height: number;
  type: string;
  target: string;
};
const props = defineProps<{ placement: string }>();
const items = ref<Item[]>([]);
const router = useRouter();
onMounted(async () => {
  try {
    const d = await api<{ items: Item[] }>('/carousel');
    items.value = d.items.filter((i) => i.enabled && i.placement === props.placement);
  } catch {}
});
function open(item: Item) {
  if (item.type === 'announcement') router.push('/announcement/' + encodeURIComponent(item.target));
  else window.open(item.target, '_blank', 'noopener,noreferrer');
}
</script>
<template>
  <div v-if="items.length" class="promo-carousel">
    <Carousel autoplay arrows :autoplay-speed="5000"
      ><div v-for="(item, index) in items" :key="index">
        <button
          class="promo-card"
          :aria-label="item.title"
          :style="{
            width: item.width + 'px',
            maxWidth: '100%',
            aspectRatio: `${item.width}/${item.height}`,
          }"
          @click="open(item)"
        >
          <img :src="item.image" :alt="item.title" />
        </button></div
    ></Carousel>
  </div>
</template>
<style scoped>
.promo-carousel {
  width: 100%;
  margin: 16px auto;
}
.promo-card {
  display: block;
  margin: auto;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
  overflow: hidden;
  border-radius: 12px;
}
.promo-card img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}
</style>
