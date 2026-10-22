import { expect, test } from "@playwright/test";
import { login } from "./helpers";

test.describe("BIWORK Agent workspace", () => {
  test("accepts the listed file types and rejects others", async ({ page }) => {
    await login(page, "operator");
    await page.goto("/agent");
    const input = page.getByLabel("Files", { exact: true });
    await expect(input).toHaveAttribute("accept", /(^|,)\.csv(,|$)/);
    await input.setInputFiles({ name: "e2e-upload.csv", mimeType: "text/csv", buffer: Buffer.from("Sana,Mahsulot,Miqdor\n01.09.2026,A mahsulot,10\n") });
    await expect(page.getByText("e2e-upload.csv")).toBeVisible();
    await expect(page.getByRole("alert")).toHaveCount(0);
    await input.setInputFiles({ name: "e2e-upload.exe", mimeType: "application/octet-stream", buffer: Buffer.from("MZ") });
    await expect(page.getByRole("alert")).toHaveText("e2e-upload.exe: this file type is not supported.");
  });

  test("a file that cannot be read fails at Parse and later stages are shown as not run", async ({ page }) => {
    await login(page, "operator");
    await page.goto("/agent");
    await page.getByLabel("Files", { exact: true }).setInputFiles({
      name: "e2e-broken.pdf", mimeType: "application/pdf", buffer: Buffer.from(`%PDF-1.4 garbage ${Date.now()}`),
    });
    await page.getByRole("button", { name: "Process" }).click();
    await expect(page).toHaveURL(/\/agent\/jobs\//);
    // The banner; the processing report repeats the sentence followed by the error message.
    await expect(page.getByText("Processing failed at stage “Parse”", { exact: true })).toBeVisible({ timeout: 30_000 });
    const stages = page.getByRole("list", { name: "Processing stages" }).getByRole("listitem");
    await expect(stages.filter({ hasText: "Interpret" })).toContainText("not run");
    await expect(stages.filter({ hasText: "Review" })).toContainText("not run");
  });
});
