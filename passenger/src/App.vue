<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import {
  user,
  demo,
  loginVisible,
  error,
  demoLogin,
  refreshUser,
  report,
  requireLogin,
} from './api';
const router = useRouter(),
  route = useRoute();
const goLogin = () => window.location.assign('/api/auth/start');
onMounted(() => refreshUser().catch(report));
const nav = (path: string) =>
  path === '/' ? router.push(path) : requireLogin(() => router.push(path));
</script>
<template>
  <div class="app-shell">
    <header class="brandbar">
      <a href="/" @click.prevent="nav('/')" class="brand"
        ><span class="brand-symbol">↗</span>TAXI<span>Link</span></a
      ><span class="brand-caption">让每一程，更从容</span
      ><span v-if="demo" class="demo-chip">演示环境</span>
    </header>
    <main><RouterView /></main>
    <nav
      v-if="['/', '/orders', '/profile'].includes(route.path)"
      class="bottom-nav"
      aria-label="主导航"
    >
      <button :class="{ active: route.path === '/' }" @click="nav('/')"><span>⌂</span>出发</button
      ><button :class="{ active: route.path === '/orders' }" @click="nav('/orders')">
        <span>☷</span>行程</button
      ><button :class="{ active: route.path === '/profile' }" @click="nav('/profile')">
        <span>◉</span>我的
      </button>
    </nav>
    <div v-if="loginVisible" class="modal-backdrop" @click.self="loginVisible = false">
      <section role="dialog" aria-modal="true" aria-labelledby="login-title" class="login-card">
        <span class="modal-emblem">↗</span>
        <h2 id="login-title">登录，开启下一程</h2>
        <p>使用 HydCraft Portal 账号登录，管理你的行程与个人资料。</p>
        <t-button block theme="primary" @click="goLogin">前往 Portal 登录</t-button
        ><t-button v-if="demo" block variant="outline" @click="demoLogin().catch(report)"
          >体验演示账号</t-button
        ><button class="text-button" @click="loginVisible = false">稍后再说</button>
      </section>
    </div>
    <div v-if="error" class="toast" role="alert">
      <span>{{ error }}</span
      ><button aria-label="关闭提示" @click="error = ''">×</button>
    </div>
  </div>
</template>
