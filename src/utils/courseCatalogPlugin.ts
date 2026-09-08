// src/utils/courseCatalogPlugin.ts
import type { AstroIntegration } from 'astro';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export interface CatalogItem {
  order: number;
  title: string;
  description: string;
  category: string;
  level: string;
  duration: string;
  totalLessons: number;
  mode: string;
  status: string;
  currency: string;
  price_full: number;
  originalPrice: number;
  discountBadge?: string;
  offerText?: string;
  installmentNumber: number;
  price_installment: number;
  installmentPlanText?: string;
}

export type CourseCatalog = Record<string, CatalogItem>;

/**
 * Robust YAML Frontmatter Parser
 */
function parseFrontmatter(content: string): Record<string, any> {
  const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---/);
  if (!match) return {};

  const lines = match[1].split(/\r?\n/);
  const data: Record<string, any> = {};

  for (const line of lines) {
    const colonIndex = line.indexOf(':');
    if (colonIndex === -1) continue;

    const key = line.slice(0, colonIndex).trim();
    let value: any = line.slice(colonIndex + 1).trim();

    // Remove quotes
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    // Type casting
    if (!isNaN(value) && value !== '') {
      value = Number(value);
    } else if (value.toLowerCase() === 'true') {
      value = true;
    } else if (value.toLowerCase() === 'false') {
      value = false;
    }

    data[key] = value;
  }
  return data;
}

/**
 * Resolve directory based on glob loader base './contents/courses'
 */
function getCoursesDir(rootPath: string): string | null {
  const possiblePaths = [
    path.resolve(rootPath, 'contents/courses'),
    path.resolve(rootPath, './contents/courses'),
    path.resolve(rootPath, 'src/contents/courses'),
  ];

  for (const dir of possiblePaths) {
    if (fs.existsSync(dir)) {
      return dir;
    }
  }
  return null;
}

/**
 * Scan markdown files and build JSON catalog adhering to Zod Schema defaults
 */
export function buildCourseCatalogJson(rootPath: string): CourseCatalog {
  const coursesDir = getCoursesDir(rootPath);
  const catalog: CourseCatalog = {};

  if (!coursesDir) {
    console.warn(`\x1b[33m[Catalog Plugin]\x1b[0m Directory './contents/courses' not found.`);
    return catalog;
  }

  // Recursive scan if nested folders exist
  function scanDir(currentDir: string) {
    const items = fs.readdirSync(currentDir, { withFileTypes: true });

    for (const item of items) {
      const fullPath = path.join(currentDir, item.name);

      if (item.isDirectory()) {
        scanDir(fullPath);
      } else if (item.name.endsWith('.md') || item.name.endsWith('.mdx')) {
        // Generate relative slug matching Astro glob loader
        const relPath = path.relative(coursesDir!, fullPath).replace(/\\/g, '/');
        const slug = relPath.replace(/\.(md|mdx)$/, '');

        const rawContent = fs.readFileSync(fullPath, 'utf-8');
        const frontmatter = parseFrontmatter(rawContent);

        if (frontmatter.title) {
          catalog[slug] = {
            order: Number(frontmatter.order ?? 999),
            title: String(frontmatter.title),
            description: String(frontmatter.description ?? ''),
            category: String(frontmatter.category ?? 'General'),
            level: String(frontmatter.level ?? 'Advanced'),
            duration: String(frontmatter.duration ?? ''),
            totalLessons: Number(frontmatter.totalLessons ?? 0),
            mode: String(frontmatter.mode ?? 'Live Mentorship'),
            status: String(frontmatter.status ?? 'Enrolling Now'),
            currency: String(frontmatter.currency ?? '₹'),
            price_full: Number(frontmatter.price ?? 0),
            originalPrice: Number(frontmatter.originalPrice ?? frontmatter.price ?? 0),
            discountBadge: frontmatter.discountBadge || '',
            offerText: frontmatter.offerText || '',
            installmentNumber: Number(frontmatter.installmentNumber ?? 1),
            price_installment: Number(frontmatter.installmentPrice ?? 0),
            installmentPlanText: frontmatter.installmentPlanText || '',
          };
        }
      }
    }
  }

  scanDir(coursesDir);
  return catalog;
}

function writeCatalogFile(destPath: string, catalog: CourseCatalog) {
  const dir = path.dirname(destPath);
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
  fs.writeFileSync(destPath, JSON.stringify(catalog, null, 2), 'utf-8');
}

/**
 * Astro Integration
 */
export default function courseCatalogPlugin(): AstroIntegration {
  let projectRoot: string = process.cwd();

  return {
    name: 'astro-course-catalog-generator',
    hooks: {
      'astro:config:setup': ({ config }) => {
        projectRoot = fileURLToPath(config.root);

        const publicDest = path.resolve(projectRoot, 'public/api/course_catalog.json');
        const catalog = buildCourseCatalogJson(projectRoot);
        writeCatalogFile(publicDest, catalog);

        console.log(
          `\x1b[32m✓\x1b[0m [Catalog Plugin] Built ${Object.keys(catalog).length} courses into public/api/course_catalog.json`
        );
      },

      'astro:server:setup': ({ server }) => {
        const coursesDir = getCoursesDir(projectRoot);
        if (coursesDir) {
          server.watcher.add(coursesDir);

          server.watcher.on('all', (event, changedPath) => {
            if (changedPath.startsWith(coursesDir)) {
              const publicDest = path.resolve(projectRoot, 'public/api/course_catalog.json');
              const catalog = buildCourseCatalogJson(projectRoot);
              writeCatalogFile(publicDest, catalog);
              console.log(
                `\x1b[36mℹ\x1b[0m [Catalog Plugin] Updated catalog from ./contents/courses`
              );
            }
          });
        }
      },

      'astro:build:done': ({ dir }) => {
        const distRoot = fileURLToPath(dir);
        const distDest = path.resolve(distRoot, 'api/course_catalog.json');
        const catalog = buildCourseCatalogJson(projectRoot);

        writeCatalogFile(distDest, catalog);
        console.log(
          `\x1b[32m✓\x1b[0m [Catalog Plugin] Emitted to dist/api/course_catalog.json`
        );
      },
    },
  };
}