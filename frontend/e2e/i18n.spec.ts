import { expect, test, type Page } from "@playwright/test";
import { login, submitText, uniqueDate } from "./helpers";

async function useLocale(page: Page, locale: "uz" | "ru" | "en") {
  await page.evaluate((l) => localStorage.setItem("biwork.locale", l), locale);
}

test.describe("Languages and accessibility", () => {
  test("Uzbek dates, numbers and labels do not depend on the browser's locale data", async ({ page }) => {
    await login(page, "admin");
    const jobId = await submitText(page, `${uniqueDate()} 1-sex 1-smena: 315 dona A mahsulot ishlab chiqarildi, 310 dona qabul qilindi, 5 dona brak. i18n e2e`);
    await useLocale(page, "uz");

    await page.goto(`/agent/jobs/${jobId}`);
    await expect(page.locator("html")).toHaveAttribute("lang", "uz");
    // Record type, field labels and report lines come from codes, not from the API's English labels.
    await expect(page.getByRole("heading", { name: /^Ishlab chiqarish hisoboti #1$/ })).toBeVisible();
    await expect(page.getByLabel("Ishlab chiqarish sanasi", { exact: true })).toBeVisible();
    await expect(page.getByText("1 ta yozuv ajratib olindi.")).toBeVisible();
    await expect(page.getByLabel("Production date", { exact: true })).toHaveCount(0);

    await page.goto("/production?tab=calendar");
    await expect(page.getByText("Dush", { exact: true })).toBeVisible();
    await expect(page.getByText(/^(yanvar|fevral|mart|aprel|may|iyun|iyul|avgust|sentabr|oktabr|noyabr|dekabr), \d{4}$/)).toBeVisible();

    await page.goto("/production");
    const firstDate = page.locator("tbody tr").first().locator("td").nth(1);
    await expect(firstDate).toHaveText(/^\d{2}-(yan|fev|mar|apr|may|iyn|iyl|avg|sen|okt|noy|dek), \d{4}$/);
    await expect(page.getByText(/\bM\d{2}\b/)).toHaveCount(0);
  });

  test("Russian counts use the right plural form", async ({ page }) => {
    await login(page, "admin");
    const jobId = await submitText(page, `${uniqueDate()} 2-sex 1-smena: 120 dona B mahsulot ishlab chiqarildi, 118 dona qabul qilindi, 2 dona brak. plural e2e`);
    await useLocale(page, "ru");
    await page.goto(`/agent/jobs/${jobId}`);
    await expect(page.getByText("1 запись", { exact: true })).toBeVisible();
  });

  test("dialogs return focus to the control that opened them", async ({ page }) => {
    await login(page, "admin");
    await page.goto("/tasks");
    const opener = page.getByRole("button", { name: "New task" });
    await opener.click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(opener).toBeFocused();
  });

  test("the phone navigation drawer is a modal dialog", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, "admin");
    await page.goto("/tasks");
    const menu = page.getByRole("button", { name: "Menu" });
    await menu.click();
    const drawer = page.getByRole("dialog", { name: "Menu" });
    await expect(drawer).toBeVisible();
    await page.keyboard.press("Tab");
    await expect(drawer.locator(":focus")).toHaveCount(1);
    await page.keyboard.press("Escape");
    await expect(drawer).toHaveCount(0);
    await expect(menu).toBeFocused();
  });
});
