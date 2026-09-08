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

const courses = defineCollection({
  loader: glob({ pattern: '**/*.{md,mdx}', base: './contents/courses' }),
  schema: z.object({
    order: z.number().default(999), // 👈 এই লাইনটি যোগ করুন (ছোট সংখ্যা = আগে দেখাবে)
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
    installmentNumber: z.number().default(1),
    installmentPrice: z.number().default(0),
    installmentPlanText: z.string().optional(),
    
    featuredImage: z.string().optional().default(''),
    videoTrailerUrl: z.string().optional().default(''),
    tags: z.array(z.string()).default([]),
    prerequisites: z.array(z.string()).default([]),

    // payment options
    upi_name: z.string().optional(),
    upi_id: z.string().optional(),
    qr_image_url: z.string().optional(),
    custom_payment_url: z.string().optional(),

    // other payment options
    other_payment_title: z.string().optional(),
    other_payment_url: z.string().optional(),
    other_payment_note: z.string().optional(),
    other_qr_image: z.string().optional(),
    other_qr_label: z.string().optional(),
  }),
});

export const collections = { articles, courses };