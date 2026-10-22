import { expect, test } from "@playwright/test";
import { login } from "./helpers";

test.describe("Module screens", () => {
  test("a task opened by link stays current after it is completed", async ({ page }) => {
    await login(page, "manager");
    await page.goto("/tasks");
    const title = `E2E task ${Date.now()}`;
    await page.getByRole("button", { name: "New task" }).click();
    await page.getByLabel("Title").fill(title);
    await page.getByRole("dialog").getByRole("button", { name: "Save" }).click();
    await page.getByRole("cell", { name: title }).click();
    await expect(page).toHaveURL(/[?&]task=/);
    const link = page.url();
    const sheet = page.getByRole("dialog");
    // Completing removes the task from the default "open and in progress" list; the panel must follow the server.
    await sheet.getByRole("button", { name: "Complete" }).click();
    await expect(sheet.getByRole("button", { name: "Reopen" })).toBeVisible();
    await expect(sheet.getByRole("button", { name: "Start" })).toHaveCount(0);
    // Notification links (/tasks?task=<id>) open the panel even for tasks outside the current filter.
    await page.goto(link);
    await expect(page.getByRole("dialog")).toContainText(title);
    await expect(page.getByRole("dialog").getByRole("button", { name: "Reopen" })).toBeVisible();
  });

  test("outgoing movements show a single minus sign", async ({ page }) => {
    await login(page, "manager");
    await page.goto("/inventory?tab=movements");
    await expect(page.getByRole("cell", { name: /^−\d/ }).first()).toBeVisible();
    await expect(page.getByRole("cell", { name: /−-/ })).toHaveCount(0);
  });

  test("operators are not offered actions that need approval or master data rights", async ({ page }) => {
    await login(page, "operator");
    await page.goto("/production");
    await page.getByRole("combobox", { name: "Status" }).selectOption("approved");
    await page.getByRole("row").filter({ hasText: "Approved" }).first().click();
    await expect(page).toHaveURL(/[?&]report=/);
    const sheet = page.getByRole("dialog");
    await expect(sheet.getByText("Approved").first()).toBeVisible();
    await expect(sheet.getByRole("button", { name: "Cancel report" })).toHaveCount(0);
    await expect(sheet.getByRole("button", { name: "Correct" })).toHaveCount(0);
    await page.goto("/inventory?tab=warehouses");
    await expect(page.getByRole("cell").first()).toBeVisible();
    await expect(page.getByRole("button", { name: "New warehouse" })).toHaveCount(0);
  });

  test("production master data lists categories, shifts and lines", async ({ page }) => {
    await login(page, "admin");
    await page.goto("/production?tab=master");
    for (const name of ["Product categories", "Shifts", "Production lines"]) {
      await expect(page.getByRole("heading", { level: 2, name })).toBeVisible();
    }
    await expect(page.getByRole("button", { name: "New category" })).toBeVisible();
    await expect(page.getByRole("button", { name: "New line" })).toBeVisible();
  });

  test("an account without a company can sign out, and a platform administrator can create one", async ({ page }) => {
    const user = { id: "u", email: "owner@fresh.test", full_name: "Fresh Owner", locale: "en", is_platform_admin: true, mfa_enabled: false, companies: [] };
    await page.route("**/api/v1/auth/refresh", (route) => route.fulfill({ json: { access_token: "e2e", user } }));
    await page.route("**/api/v1/auth/logout", (route) => route.fulfill({ status: 204 }));
    await page.goto("/login");
    await page.evaluate(() => localStorage.setItem("biwork.locale", "en"));
    await page.goto("/");
    await expect(page.getByText("As a platform administrator you can create the first one.")).toBeVisible();
    await page.getByRole("button", { name: "New company" }).click();
    await expect(page.getByRole("dialog", { name: "New company" })).toBeVisible();
    await page.getByRole("dialog").getByRole("button", { name: "Cancel" }).click();
    await page.unroute("**/api/v1/auth/refresh");
    await page.getByRole("button", { name: "Sign out" }).click();
    await expect(page).toHaveURL(/\/login/);
  });
});
