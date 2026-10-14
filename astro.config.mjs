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
  redirects: {
    '/training': '/courses',
    '/trainings/': '/courses',
    '/mentoring': '/courses',
    '/mentors': '/courses',
    '/online-training': '/courses',
    '/trainings/react-native-app-development/': '/courses/mobile-app-engineering-react-native',
    '/trainings/master-in-nodejs-expressjs-mongodb/': '/courses/enterprise-nestjs-cqrs-architecture/',
    '/trainings/master-in-agentic-ai/': '/courses/autonomous-agentic-ai-mcp-architecture/',
    '/trainings/advanced-flutter-training-course/': '/courses/production-flutter-dart-engineering/',
    '/trainings/mean-stack-training/': '/courses/enterprise-nestjs-cqrs-architecture/',
    
    // This is for the old URL structure
    '/trainings/master-in-laravel-app-development/': 'https://cms.shibajidebnath.com/trainings/master-in-laravel-app-development/',
    '/trainings/master-in-wordpress-development-training/': 'https://cms.shibajidebnath.com/trainings/master-in-wordpress-development-training/',
    '/trainings/master-in-python-programming/': 'https://cms.shibajidebnath.com/trainings/master-in-python-programming/',
    '/trainings/mern-stack-course/': 'https://cms.shibajidebnath.com/trainings/mern-stack-course/',
    '/trainings/master-in-website-designing-training/': 'https://cms.shibajidebnath.com/trainings/master-in-website-designing-training/',
    '/trainings/master-in-advanced-javascript/': 'https://cms.shibajidebnath.com/trainings/master-in-advanced-javascript/',
  },
  
  markdown: {
    shikiConfig: {
      theme: 'one-dark-pro', // প্রিমিয়াম VS Code ডার্ক থিম
      wrap: true,
    },
  },

  vite: {
    plugins: [tailwindcss()],
    server: {
      // Vite ডেভ সার্ভার শুধু চেনা Host হেডার গ্রহণ করে। টানেল (cloudflared/ngrok)
      // নিজের হোস্টনেম পাঠায়, ফলে "Blocked request. This host is not allowed."
      // আসে। শুরুতে ডট (`.`) দিলে ওই ডোমেইন ও তার সব সাবডোমেইন অনুমোদিত হয়,
      // তাই প্রতিবার নতুন trycloudflare হোস্টনেম বদলালেও সমস্যা হয় না।
      allowedHosts: [
        '.trycloudflare.com',
        '.ngrok-free.app',
        '.ngrok.io',
        '.loca.lt',
      ],
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