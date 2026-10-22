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

  test("a link to a task the user cannot find says so", async ({ page }) => {
    await login(page, "manager");
    await page.goto("/tasks?task=01a00000-0000-7000-8000-000000000000");
    await expect(page.getByRole("dialog")).toContainText("This task was not found.");
  });

  test("approved output that did not reach stock is reported on the report", async ({ page }) => {
    await login(page, "manager");
    await page.route("**/api/v1/production/reports/*", async (route) => {
      if (route.request().method() !== "GET") return route.continue();
      const response = await route.fetch();
      const report = await response.json();
      await route.fulfill({ response, json: { ...report, warnings: [{ code: "output_not_posted", message: "fallback" }] } });
    });
    await page.goto("/production");
    await page.getByRole("combobox", { name: "Status" }).selectOption("approved");
    await page.getByRole("row").filter({ hasText: "Approved" }).first().click();
    await expect(page.getByRole("dialog")).toContainText("The output was not added to stock: no finished goods warehouse was set");
  });

  test("the overview stays the home page when the Analytics module is off", async ({ page }) => {
    await page.route("**/api/v1/company", async (route) => {
      if (route.request().method() !== "GET") return route.continue();
      const response = await route.fetch();
      const company = await response.json();
      for (const m of company.modules) if (m.code === "analytics") m.enabled = false;
      await route.fulfill({ response, json: company });
    });
    await login(page, "admin");
    await page.goto("/");
    await expect(page.getByRole("heading", { level: 1, name: "Overview" })).toBeVisible();
    const nav = page.getByRole("navigation", { name: "Main navigation" });
    await expect(nav.getByRole("link", { name: "Overview" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Analytics" })).toHaveCount(0);
  });

  test("a revoked session refreshes, or signs out when the refresh is refused", async ({ page }) => {
    await login(page, "viewer");
    // The access token predates a password change elsewhere; this browser's refresh cookie still works.
    let revoked = true;
    await page.route("**/api/v1/tasks**", (route) => {
      if (!revoked) return route.continue();
      revoked = false;
      return route.fulfill({ status: 401, json: { status: 401, code: "session_revoked", detail: "Session ended" } });
    });
    await page.getByRole("link", { name: "Tasks" }).click();
    await expect(page.getByRole("heading", { level: 1, name: "Tasks" })).toBeVisible();
    await expect(page.getByRole("row").nth(1)).toBeVisible();
    await page.unroute("**/api/v1/tasks**");
    // Here the refresh cookie was revoked as well: the user is sent to the sign-in page.
    await page.route("**/api/v1/auth/refresh", (route) => route.fulfill({ status: 401, json: { status: 401, code: "invalid_refresh" } }));
    await page.route("**/api/v1/production/**", (route) => route.fulfill({ status: 401, json: { status: 401, code: "session_revoked", detail: "Session ended" } }));
    await page.getByRole("link", { name: "Production" }).click();
    await expect(page).toHaveURL(/\/login/);
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
