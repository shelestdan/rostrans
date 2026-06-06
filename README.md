# РУТРАНС one-page

Static Astro site for Timeweb minimal hosting with a PHP lead endpoint.

## Local commands

```bash
npm install
npm run dev
npm run build
npm run typecheck
php -l public/lead.php
php -l public/config.example.php
```

## Timeweb deploy

1. Build locally with the real domain. This value is used for canonical URLs, Open Graph, robots.txt, and sitemap.xml:

```bash
PUBLIC_SITE_URL=https://your-domain.ru npm run build
```

2. Upload everything from `dist/` to the site root on Timeweb.
3. Copy `public/config.example.php` to `config.php` in the uploaded site root.
4. Fill `config.php` with the Resend API key and a verified sender domain.

`config.php` is ignored by git and blocked by `.htaccess`, but the safest setup is to keep server secrets out of version control.
