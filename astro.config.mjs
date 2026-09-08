// @ts-check
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'astro/config';
import { generateLlmsFile } from './src/utils/generateLlms';
import sitemap from '@astrojs/sitemap';
import courseCatalogPlugin from './src/utils/courseCatalogPlugin';

// https://astro.build/config
export default defineConfig({
  output: 'static', // প্রতি পেজ সম্পূর্ণ স্ট্যাটিক HTML হিসেবে বিল্ড হবে
  site: 'https://shibajidebnath.com', // প্রোডাকশন ডোমেন
  
  markdown: {
    shikiConfig: {
      theme: 'one-dark-pro', // প্রিমিয়াম VS Code ডার্ক থিম
      wrap: true,
    },
  },

  vite: {
    plugins: [tailwindcss()],
    server: {
      // লোকাল ডেভেলপমেন্টে /api/* রিকোয়েস্টগুলো PHP সার্ভারে (localhost:8000) প্রক্সি করবে
      proxy: {
        '/api': {
          target: 'http://127.0.0.1:8000',
          changeOrigin: true,
          secure: false,
        },
      },
    },
  },

  integrations: [
    courseCatalogPlugin(),
    sitemap({
      // টেকনিক্যাল ফাইল ও ডায়নামিক এন্ডপয়েন্ট সাইটম্যাপ থেকে বাদ দেওয়া
      filter: (page) => !page.includes('/api/') && !page.includes('/courses/payment-success'),
    }),
    {
      name: 'auto-llms-generator',
      hooks: {
        'astro:build:done': async () => {
          await generateLlmsFile();
        },
      },
    },
  ],
});