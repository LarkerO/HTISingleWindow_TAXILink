<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { api, user, requireLogin, report, refreshUser } from '../api';
const route = useRoute(),
  joined = ref(false),
  busy = ref(false);
onMounted(() => {
  if (!user.value) requireLogin(() => refreshUser());
});
async function accept() {
  requireLogin(async () => {
    busy.value = true;
    try {
      await api('/invitations/accept', { token: route.query.invite });
      joined.value = true;
      await refreshUser();
    } catch (e) {
      report(e);
    } finally {
      busy.value = false;
    }
  });
}
async function logout() {
  try {
    await api('/auth/logout', {});
    user.value = null;
  } catch (e) {
    report(e);
  }
}
</script>
<template>
  <section class="welcome compact">
    <div class="eyebrow">YOUR PERSONAL SPACE</div>
    <h1>我的</h1>
    <p class="muted">很高兴与你一路同行。</p>
  </section>
  <section v-if="user" class="profile-card surface">
    <img
      v-if="user.avatar"
      :src="user.avatar"
      alt="个人头像"
      class="avatar"
      referrerpolicy="no-referrer"
    />
    <div v-else class="avatar">{{ user.username[0] }}</div>
    <h2>{{ user.username }}</h2>
    <span class="status-tag">HydCraft Portal 账号</span>
    <div class="profile-detail">
      <span>首次登录 TAXILink</span
      ><strong>{{ new Date(user.firstLogin * 1000).toLocaleDateString('zh-CN') }}</strong>
    </div>
    <div class="profile-detail"><span>账号状态</span><strong>已登录</strong></div>
  </section>
  <section v-else class="surface empty">
    <h2>登录后查看个人资料</h2>
    <t-button theme="primary" @click="requireLogin(() => refreshUser())">前往登录</t-button>
  </section>
  <section v-if="route.query.invite" class="surface invitation">
    <h2>{{ joined ? '已加入企业' : '你收到一份企业邀请' }}</h2>
    <p>接受后，你可以使用此账号切换企业管理身份。</p>
    <t-button v-if="!joined" theme="primary" :loading="busy" @click="accept">接受邀请</t-button>
  </section>
  <section v-if="user?.memberships.length" class="surface memberships">
    <h2>我的管理身份</h2>
    <div v-for="m in user.memberships" :key="m.company_id + m.role" class="profile-detail">
      <strong>{{ m.company_name }}</strong
      ><span>{{ m.role === 'admin' ? '企业管理员' : '企业调度员' }}</span>
    </div>
  </section>
  <p class="privacy-note">无需绑定游戏账号，也可以预约出行。</p>
  <button v-if="user" class="logout-button" @click="logout">退出登录</button>
</template>
