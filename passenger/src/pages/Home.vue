<script setup lang="ts">
import { useRouter } from 'vue-router';
import { user, requireLogin, report } from '../api';
import { pickup, dropoff, selectPoint } from '../booking';
import RouteMap from '../components/RouteMap.vue';
const router = useRouter();
function book() {
  if (!pickup.value || !dropoff.value) {
    report('请先选择上车点和下车点');
    return;
  }
  if (pickup.value.id === dropoff.value.id) {
    report('请选择不同的上下车点');
    return;
  }
  requireLogin(() => router.push('/vehicles'));
}
function swap() {
  const a = pickup.value,
    b = dropoff.value;
  if (a && b) {
    selectPoint('pickup', b);
    selectPoint('dropoff', a);
  }
}
</script>
<template>
  <section class="welcome">
    <div class="eyebrow">A BETTER WAY TO GO</div>
    <p class="greeting">{{ user ? `你好，${user.username}` : '你好，旅人' }} <span>☀</span></p>
    <h1>下一站，<br />想去哪里<span class="accent">？</span></h1>
    <p class="muted">连接城市的每一处美好。</p>
  </section>
  <section class="booking-card">
    <div class="section-kicker">
      <span class="live-dot"></span>随时出发，自在抵达 <span class="small-tag">轨道出行</span>
    </div>
    <div class="location-pair">
      <div class="location-line">
        <i class="point-dot green"></i
        ><button @click="router.push('/points/pickup')">
          <span>从哪里出发</span><strong>{{ pickup?.name || '选择上车点' }}</strong>
        </button>
      </div>
      <div class="location-line">
        <i class="point-dot amber"></i
        ><button @click="router.push('/points/dropoff')">
          <span>想去的地方</span><strong>{{ dropoff?.name || '选择下车点' }}</strong>
        </button>
      </div>
      <button class="swap-button" aria-label="交换上下车点" @click="swap">⇅</button>
    </div>
    <t-button block size="large" theme="primary" @click="book"
      >确认打车 <span class="button-arrow">↗</span></t-button
    >
    <p class="booking-note">多家运营企业 · 明码报价 · 自由选择</p>
  </section>
  <div class="section-heading">
    <h2>城市，在你身边</h2>
    <span>每一程都值得期待 ↗</span>
  </div>
  <div class="home-map"><RouteMap home /></div>
  <div class="feature-strip">
    <div><span>◈</span><strong>清晰计价</strong><small>费用明细一目了然</small></div>
    <div><span>⌁</span><strong>实时轨迹</strong><small>随时掌握行程进度</small></div>
    <div><span>✧</span><strong>自在选择</strong><small>选择喜欢的运营企业</small></div>
  </div>
</template>
