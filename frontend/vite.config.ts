import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

const backend = process.env.BIWORK_API_URL ?? "http://127.0.0.1:8000";

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { "@": "/src" } },
  server: { port: 5173, proxy: { "/api": { target: backend, changeOrigin: false } } },
  preview: { port: 4173, proxy: { "/api": { target: backend, changeOrigin: false } } },
  build: { sourcemap: true, chunkSizeWarningLimit: 1200 },
  test: { environment: "jsdom", include: ["src/**/*.test.{ts,tsx}"] },
});
