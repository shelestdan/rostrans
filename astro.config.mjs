import { defineConfig } from "astro/config";

export default defineConfig({
  output: "static",
  base: process.env.BASE_PATH || "/",
  site: process.env.PUBLIC_SITE_URL || undefined
});
