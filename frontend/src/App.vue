<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <header class="masthead">
    <div class="masthead-inner">
      <RouterLink to="/" class="brand">Nest<em>egg</em></RouterLink>
      <nav></nav>
    </div>
  </header>
  <main class="page">
    <RouterView />
  </main>
</template>
