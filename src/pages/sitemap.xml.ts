import { siteUrl } from "../lib/site";

export function GET() {
  const urls = ["/", "/privacy/"].map((path) => new URL(path, siteUrl).toString());

  return new Response(
    `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls
  .map(
    (url) => `  <url>
    <loc>${url}</loc>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>`
  )
  .join("\n")}
</urlset>`,
    {
      headers: {
        "Content-Type": "application/xml; charset=utf-8"
      }
    }
  );
}
