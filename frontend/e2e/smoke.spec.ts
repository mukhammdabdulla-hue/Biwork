import { expect, test } from "@playwright/test";
import { login } from "./helpers";

const PAGES: [string, string][] = [
  ["/", "Overview"], ["/agent", "BIWORK Agent"], ["/tasks", "Tasks"], ["/production", "Production"], ["/production?tab=calendar", "Production"],
  ["/inventory", "Inventory"], ["/supply", "Supply"], ["/quality", "Quality"], ["/analytics?tab=agent", "Analytics"], ["/hr", "HR"],
  ["/admin", "Administration"], ["/admin?tab=audit", "Administration"], ["/settings", "Settings"],
];

test("every main page renders without client errors", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("response", (r) => { if (r.url().includes("/api/v1/") && r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
  await login(page, "admin");
  for (const [path, title] of PAGES) {
    await page.goto(path);
    await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
  }
  await expect(page.getByText("Sample data").first()).toBeVisible();
  expect(errors).toEqual([]);
});

test("viewer has read-only access", async ({ page }) => {
  await login(page, "viewer");
  await page.goto("/production");
  await expect(page.getByRole("heading", { level: 1, name: "Production" })).toBeVisible();
  await expect(page.getByRole("button", { name: "New report" })).toHaveCount(0);
  await page.goto("/agent");
  await expect(page.getByRole("button", { name: "Process" })).toHaveCount(0);
});
