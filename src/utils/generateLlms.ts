// src/utils/generateLlms.ts
import fs from 'node:fs';
import path from 'node:path';

interface ContentItem {
  id: string;
  title: string;
  desc: string;
  pubDate: string;
}

interface ModuleConfig {
  sectionTitle: string;
  dirName: string;
  urlPrefix: string;
}

interface PageRoute {
  title: string;
  url: string;
}

// 1. Frontmatter Parser Helper
function parseFrontmatter(fileContent: string): Record<string, string> {
  const frontmatterRegex = /^---\r?\n([\s\S]*?)\r?\n---/;
  const match = fileContent.match(frontmatterRegex);
  if (!match) return {};

  const lines = match[1].split(/\r?\n/);
  const data: Record<string, string> = {};

  for (const line of lines) {
    const colonIndex = line.indexOf(':');
    if (colonIndex !== -1) {
      const key = line.slice(0, colonIndex).trim();
      let val = line.slice(colonIndex + 1).trim();

      if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
        val = val.slice(1, -1);
      }

      data[key] = val;
    }
  }

  return data;
}

// 2. Universal Collection Loader
function loadCollectionItems(directoryName: string): ContentItem[] {
  const resolvedPath = fs.existsSync(path.resolve(`./src/content/${directoryName}`))
    ? path.resolve(`./src/content/${directoryName}`)
    : path.resolve(`./contents/${directoryName}`);

  if (!fs.existsSync(resolvedPath)) return [];

  const files = fs.readdirSync(resolvedPath).filter(file => file.endsWith('.md') || file.endsWith('.mdx'));
  const items: ContentItem[] = [];

  for (const file of files) {
    const filePath = path.join(resolvedPath, file);
    const rawContent = fs.readFileSync(filePath, 'utf-8');
    const data = parseFrontmatter(rawContent);

    const id = file.replace(/\.(md|mdx)$/, '');
    const title = data.title || id;
    const desc = data.summary || data.description || '';
    const pubDate = data.pubDate || '1970-01-01';

    items.push({ id, title, desc, pubDate });
  }

  return items.sort((a, b) => new Date(b.pubDate).getTime() - new Date(a.pubDate).getTime());
}

// 3. Helper to format clean titles from route paths
function formatRouteTitle(cleanRoute: string): string {
  if (!cleanRoute) return 'Home / Overview';

  const routeTitleMap: Record<string, string> = {
    experience: 'Curated Engineering Experience',
    'case-studies': 'Architectural Case Studies',
    articles: 'Articles & Architectural Blueprints',
    courses: 'Software Engineering Courses & Mentorship',
    contact: 'Contact / Advisory Portal',
    privacy: 'Privacy Policy & Data Architecture',
    terms: 'Terms of Service & Advisory Governance',
    'courses/checkout': 'Course Checkout Terminal',
  };

  if (routeTitleMap[cleanRoute]) {
    return routeTitleMap[cleanRoute];
  }

  // Fallback: kebab-case to Title Case (e.g. my-services -> My Services)
  return cleanRoute
    .split('/')
    .pop()!
    .split('-')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

// 4. Auto-discover Static Pages from src/pages
function discoverStaticPages(siteUrl: string): PageRoute[] {
  const pagesDir = path.resolve('./src/pages');
  if (!fs.existsSync(pagesDir)) return [];

  const routes: PageRoute[] = [];

  function scan(dir: string, baseRoute: string) {
    const entries = fs.readdirSync(dir, { withFileTypes: true });

    for (const entry of entries) {
      if (entry.isDirectory()) {
        // Skip dynamic API or special folders if needed
        if (entry.name === 'api') continue;
        scan(path.join(dir, entry.name), `${baseRoute}${entry.name}/`);
      } else if (entry.isFile() && (entry.name.endsWith('.astro') || entry.name.endsWith('.md'))) {
        // Exclude dynamic routes (e.g. [...slug].astro, [id].astro) and error pages (404)
        if (entry.name.includes('[') || entry.name.startsWith('404')) continue;

        let routeSegment = entry.name.replace(/\.(astro|md)$/, '');
        let cleanRoute = routeSegment === 'index' ? baseRoute : `${baseRoute}${routeSegment}`;
        
        // Normalize slashes
        cleanRoute = cleanRoute.replace(/^\/+|\/+$/g, '');

        const url = cleanRoute ? `${siteUrl}/${cleanRoute}` : siteUrl;
        routes.push({
          title: formatRouteTitle(cleanRoute),
          url,
        });
      }
    }
  }

  scan(pagesDir, '');

  // Sort home page first, then others alphabetically
  return routes.sort((a, b) => {
    if (a.url === siteUrl) return -1;
    if (b.url === siteUrl) return 1;
    return a.url.localeCompare(b.url);
  });
}

// 5. Main Generator
export async function generateLlmsFile() {
  const siteUrl = 'https://shibajidebnath.com';

  const modules: ModuleConfig[] = [
    {
      sectionTitle: 'Published Architectural Blueprints & Articles',
      dirName: 'articles',
      urlPrefix: 'articles',
    },
    {
      sectionTitle: 'Software Production Engineering Courses & Bootcamps',
      dirName: 'courses',
      urlPrefix: 'courses',
    },
    {
      sectionTitle: 'Enterprise Case Studies & Audits',
      dirName: 'case-studies',
      urlPrefix: 'case-studies',
    },
  ];

  // Auto-fetch static page coordinates
  const primaryPages = discoverStaticPages(siteUrl);

  let content = `# Shibaji Debnath - System Architect & Fractional CTO, Mentor
> Senior Full-Stack Software Engineer, Mentor & Fractional CTO specializing in high-concurrency distributed systems, applied agentic AI, and microservice architectures.

## Core Capabilities
- Distributed Systems Architecture (NestJS, PHP, PostgreSQL, Redis)
- Autonomous AI Agent Workflows (n8n, Flowise, Ollama, MCP Servers)
- Cloud Infrastructure & DevOps (Docker, Linux VPS, Traefik, CI/CD)
- Fractional CTO Advisory & Architectural Audits
- Software Engineering Courses & Mentorship

## Primary Coordinates
`;

  for (const page of primaryPages) {
    content += `- ${page.title}: ${page.url}\n`;
  }

  // Dynamically iterate over configured modules
  for (const mod of modules) {
    const items = loadCollectionItems(mod.dirName);
    if (items.length === 0) continue;

    content += `\n## ${mod.sectionTitle}\n`;
    for (const item of items) {
      const url = `${siteUrl}/${mod.urlPrefix}/${item.id}`;
      content += `- [${item.title}](${url}): ${item.desc}\n`;
    }
  }

  // Write outputs
  const publicPath = path.resolve('./public/llms.txt');
  fs.writeFileSync(publicPath, content, 'utf-8');

  const distDir = path.resolve('./dist');
  if (fs.existsSync(distDir)) {
    const distPath = path.resolve(distDir, 'llms.txt');
    fs.writeFileSync(distPath, content, 'utf-8');
  }

  console.log('✓ Successfully auto-generated extensible llms.txt with dynamic pages');
}