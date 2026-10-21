import { expect, type Page } from "@playwright/test";

export const PASSWORD = process.env.E2E_PASSWORD ?? "Biwork2026!demo";

export async function login(page: Page, who: "admin" | "manager" | "operator" | "viewer") {
  await page.goto("/login");
  await page.evaluate(() => localStorage.setItem("biwork.locale", "en"));
  await page.reload();
  await page.getByLabel("Email").fill(`${who}@biwork.local`);
  await page.getByLabel("Password").fill(PASSWORD);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

export async function logout(page: Page) {
  await page.locator("header button.rounded-full").click();
  await page.getByRole("menuitem", { name: "Sign out" }).click();
  await expect(page).toHaveURL(/\/login/);
}

/** A production date inside the last year but before the seeded history, so each run is a new report. */
export function uniqueDate(): string {
  const d = new Date();
  d.setDate(d.getDate() - (60 + Math.floor(Math.random() * 280)));
  return `${String(d.getDate()).padStart(2, "0")}.${String(d.getMonth() + 1).padStart(2, "0")}.${d.getFullYear()}`;
}

export async function submitText(page: Page, text: string) {
  await page.goto("/agent");
  await page.getByRole("textbox", { name: "Text" }).fill(text);
  await page.getByRole("button", { name: "Process" }).click();
  const duplicate = page.getByRole("heading", { name: "Already submitted" });
  await expect(page.getByRole("heading", { name: "Already submitted" }).or(page.getByText("Extracted records"))).toBeVisible();
  if (await duplicate.isVisible()) await page.getByRole("button", { name: "Process anyway" }).click();
  await expect(page).toHaveURL(/\/agent\/jobs\//);
  await expect(page.getByText("Awaiting review").first()).toBeVisible();
  return page.url().split("/agent/jobs/")[1].split("?")[0];
}
