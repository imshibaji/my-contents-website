import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const articles = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './contents/articles' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    summary: z.string().optional(),            // দ্রুত পড়ার জন্য এক্সিকিউটিভ সামারি
    category: z.string().default("Architecture"), // আর্টিকেলের ক্যাটাগরি
    pubDate: z.date(),
    tags: z.array(z.string()),
    readingTime: z.string().default("6 min read"),
    featuredImage: z.string().optional(),
    videoUrl: z.string().optional(),
    videoDuration: z.string().optional(),
  }),
});

export const collections = { articles };