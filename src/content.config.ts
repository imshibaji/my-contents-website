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

// নতুন Courses Module Schema
// const courses = defineCollection({
//   loader: glob({ pattern: '**/*.{md,mdx}', base: './contents/courses' }),
//   schema: z.object({
//     title: z.string(),
//     description: z.string(),
//     summary: z.string().optional(),
//     category: z.string().default("System Architecture"),
//     level: z.enum(["Beginner", "Intermediate", "Advanced", "All Levels"]).default("Intermediate"),
//     duration: z.string(), // যেমন: "6 Weeks", "12 Hours"
//     totalLessons: z.number().default(10),
//     language: z.string().default("Bengali & English"),
//     mode: z.enum(["Live Mentorship", "Self-Paced", "Hybrid"]).default("Live Mentorship"),
//     status: z.enum(["Enrolling Now", "Upcoming", "Archived"]).default("Enrolling Now"),
//     featuredImage: z.string().optional(),
//     videoTrailerUrl: z.string().optional(), // ইউটিউব বা ডেমো ভিডিও ট্রেলার
//     tags: z.array(z.string()),
//     prerequisites: z.array(z.string()).optional(),
//     price: z.number(), // 0 হলে Free, 0 এর বেশি হলে আসল বিক্রয়মূল্য (যেমন: 4999)
//     originalPrice: z.number().optional(), // স্ট্রাইকথ্রু বা ডিসকাউন্টের আগের দাম (যেমন: 9999)
//     discountBadge: z.string().optional(), // যেমন: "50% OFF" বা "Limited Deal"
//   }),
// });

const courses = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './contents/courses' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    summary: z.string().optional(),
    category: z.string(),
    
    // z.enum এর বদলে z.string() ব্যবহার করলে আর কোনোদিন অমিল জনিত এরর আসবে না
    level: z.string().default('Advanced'),
    duration: z.string(),
    totalLessons: z.number().default(0),
    language: z.string().default('Bengali & English'),
    
    // mode কে z.string() করে দিন
    mode: z.string().default('Live Mentorship'),
    
    status: z.string().default('Enrolling Now'),
    currency: z.string().default('₹'),
    price: z.number().default(0),
    originalPrice: z.number().default(0),
    discountBadge: z.string().optional(),
    offerText: z.string().optional(),
    installmentPrice: z.number().default(0),
    
    featuredImage: z.string().optional().default(''),
    videoTrailerUrl: z.string().optional().default(''),
    tags: z.array(z.string()).default([]),
    prerequisites: z.array(z.string()).default([]),
  }),
});

export const collections = { articles, courses };