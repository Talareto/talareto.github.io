// Mapa strony dla Google: /sitemap.xml
// Generuje się sama przy każdym buildzie — nowe artykuły trafiają tu automatycznie.
import type { APIRoute } from "astro";
import { getCollection } from "astro:content";

export const GET: APIRoute = async ({ site }) => {
  const base = site ?? new URL("https://canlead.pl");
  const posts = await getCollection("blog", ({ data }) => !data.draft);
  const day = (d: Date) => d.toISOString().slice(0, 10);
  const newest = posts.reduce((max, p) => Math.max(max, (p.data.updated ?? p.data.date).getTime()), 0);

  const pages = [
    { path: "/", priority: "1.0" },
    { path: "/blog/", priority: "0.8", lastmod: newest ? day(new Date(newest)) : undefined },
    ...posts.map((p) => ({ path: `/blog/${p.id}/`, priority: "0.7", lastmod: day(p.data.updated ?? p.data.date) })),
    { path: "/regulamin/", priority: "0.2" },
    { path: "/polityka-prywatnosci/", priority: "0.2" },
  ];

  const urls = pages
    .map((p) => `  <url>\n    <loc>${new URL(p.path, base).href}</loc>${p.lastmod ? `\n    <lastmod>${p.lastmod}</lastmod>` : ""}\n    <priority>${p.priority}</priority>\n  </url>`)
    .join("\n");

  return new Response(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`, {
    headers: { "Content-Type": "application/xml; charset=utf-8" },
  });
};
