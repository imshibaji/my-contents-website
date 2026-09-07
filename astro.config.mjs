// @ts-check
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'astro/config';

import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  output: 'static', // নিশ্চিত করে যে প্রতি পেজ সম্পূর্ণ স্ট্যাটিক HTML হিসেবে বিল্ড হবে
  site: 'https://shibajidebnath.com', // আপনার প্রোডাকশন ডোমেন
  markdown: {
    shikiConfig: {
      theme: 'one-dark-pro', // প্রিমিয়াম VS Code ডার্ক থিম
      wrap: true,
    },
  },
  vite: {
      plugins: [tailwindcss()],
  },
  integrations: [sitemap()],
});