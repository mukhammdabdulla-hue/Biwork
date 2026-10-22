import { expect, test } from "@playwright/test";
import { login, logout, submitText, uniqueDate } from "./helpers";

test.describe("Scenario A — text submitted to BIWORK Agent becomes a production report", () => {
  test("operator submits, manager approves and saves, provenance is traceable", async ({ page }) => {
    const date = uniqueDate();
    const shift = 1 + Math.floor(Math.random() * 2);
    const text = `${date} kuni 1-sexda 500 dona A mahsulot ishlab chiqarildi. 480 dona sifat nazoratidan o'tdi, 20 donasi brak bo'ldi. ${shift}-smena.`;

    await login(page, "operator");
    const jobId = await submitText(page, text);

    // The mock adapter is labelled as such everywhere.
    await expect(page.getByText(/Mock AI — not real analysis/).first()).toBeVisible();

    // Extracted values with their sources and matches.
    const panel = page.getByRole("region", { name: /Production report #1/ });
    await expect(panel).toBeVisible();
    await expect(panel.getByRole("textbox", { name: "Quantity produced" })).toHaveValue("500");
    await expect(panel.getByRole("textbox", { name: "Quantity accepted" })).toHaveValue("480");
    await expect(panel.getByRole("textbox", { name: "Quantity defective" })).toHaveValue("20");
    await expect(panel.getByRole("button", { name: /A-001/ })).toBeVisible();
    await expect(panel.getByRole("button", { name: /1-sex/ })).toBeVisible();
    await expect(panel.getByText("Accepted + defective = produced (500)").first()).toBeVisible();

    // Operators review but cannot approve production data.
    await expect(panel.getByRole("button", { name: "Approve", exact: true })).toHaveCount(0);
    await logout(page);

    await login(page, "manager");
    await page.goto(`/agent/jobs/${jobId}`);
    await page.getByRole("button", { name: "Approve", exact: true }).click();
    await expect(page.getByText("Record approved")).toBeVisible();
    await page.getByRole("button", { name: /Save approved \(1\)/ }).click();
    await expect(page.getByText(/Saved as PRD-\d{4}-\d{6}/).first()).toBeVisible();
    await expect(page.getByText("Completed").first()).toBeVisible();

    // Provenance: original input → extraction → approval → database write.
    await page.getByRole("link", { name: "Open provenance →" }).click();
    await expect(page.getByRole("heading", { name: "Provenance" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Original input" })).toBeVisible();
    await expect(page.getByText(text)).toBeVisible();
    await expect(page.getByRole("heading", { name: "Database change" })).toBeVisible();
    await expect(page.getByText("production_reports").first()).toBeVisible();

    // The saved report is visible in Production with an Agent badge.
    await page.goto("/production");
    await expect(page.getByRole("cell", { name: /PRD-\d{4}-\d{6}/ }).first()).toBeVisible();
  });

  test("missing values are never invented and must be corrected before approval", async ({ page }) => {
    await login(page, "manager");
    await submitText(page, `${uniqueDate()} kuni 120 dona B mahsulot ishlab chiqarildi.`);
    await expect(page.getByText("Needs correction").first()).toBeVisible();
    await expect(page.getByText("Production unit is required but was not found in the source").first()).toBeVisible();
    const approve = page.getByRole("button", { name: "Approve", exact: true });
    await expect(approve).toBeDisabled();

    // Choose the production unit from master data.
    await page.getByRole("button", { name: "Choose…" }).first().click();
    await page.getByPlaceholder(/Search department/).fill("2-sex");
    await page.getByRole("option", { name: /2-sex/ }).first().click();
    await page.getByRole("button", { name: "Save & approve" }).click();
    await expect(page.getByText("Record approved")).toBeVisible();
  });

  test("a wrongly extracted optional reference can be cleared before approval", async ({ page }) => {
    await login(page, "manager");
    await submitText(page, `${uniqueDate()} kuni 1-sexda 300 dona A mahsulot ishlab chiqarildi. 290 dona sifat nazoratidan o'tdi, 10 donasi brak bo'ldi. 7-smena.`);
    const panel = page.getByRole("region", { name: /Production report #1/ });
    // No shift "7" exists, so the extracted value is invalid and blocks approval.
    await expect(panel.getByRole("button", { name: "Approve", exact: true })).toBeDisabled();
    await panel.getByRole("button", { name: "Clear: Shift" }).click();
    await expect(panel.getByText("will be cleared when you save")).toBeVisible();
    await panel.getByRole("button", { name: "Save & approve" }).click();
    await expect(page.getByText("Record approved")).toBeVisible();
  });
});
