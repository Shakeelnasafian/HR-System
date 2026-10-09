import { test, expect } from "@playwright/test";
import { TOTP, Secret } from "otpauth";
test("real cookie login, privileged MFA enrollment and company scope", async ({
  page,
}) => {
  const password = process.env.HR_DEMO_PASSWORD;
  if (!password)
    throw new Error("HR_DEMO_PASSWORD is required for the synthetic fixture");
  const bad = await page.request.post("/login", {
    data: { email: "admin@example.test", password },
    headers: { Accept: "application/json", Origin: "http://localhost:8080" },
  });
  expect(bad.status()).toBe(419);
  await page.goto("/login");
  await page.getByLabel("Work email").fill("admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Your organization" }),
  ).toBeVisible();
  await page.getByLabel("Active tenant").selectOption({ label: "Demo Group" });
  await expect(
    page.getByRole("heading", { name: "Two-factor authentication" }),
  ).toBeVisible();
  await page.getByLabel("Current password").fill(password);
  await page.getByRole("button", { name: "Set up authenticator" }).click();
  const secret = await page.locator("code.secret").innerText();
  const totp = new TOTP({
    secret: Secret.fromBase32(secret),
    digits: 6,
    period: 30,
    algorithm: "SHA1",
  });
  const enrolledCode = totp.generate();
  await page.getByLabel("Authenticator code").fill(enrolledCode);
  await page.getByRole("button", { name: "Confirm enrollment" }).click();
  await expect(page.locator(".codes li")).toHaveCount(8);
  await page.getByRole("button", { name: "Saved — sign in with MFA" }).click();
  await page.getByLabel("Work email").fill("admin@example.test");
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Verify your identity" }),
  ).toBeVisible();
  // Enrollment consumed the current time-step code; wait for a new one.
  await expect
    .poll(() => totp.generate(), { timeout: 35000, intervals: [1000] })
    .not.toBe(enrolledCode);
  await page.getByLabel("Authenticator code").fill(totp.generate());
  await page.getByRole("button", { name: "Verify and continue" }).click();
  await page.getByLabel("Active tenant").selectOption({ label: "Demo Group" });
  await expect(
    page.getByRole("heading", { name: "Demo Company A" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Demo Company B" }),
  ).toBeVisible();
  const token = await page.getByLabel("Active tenant").inputValue();
  const api = await page.request.get("/api/v1/companies", {
    headers: {
      Accept: "application/json",
      Origin: "http://localhost:8080",
      "X-Tenant-ID": token,
    },
  });
  expect(api.ok()).toBeTruthy();
  expect((await api.json()).data).toHaveLength(2);
  await page.getByRole("button", { name: "Open DEMO-1" }).click();
  await page.getByRole("button", { name: "Organization", exact: true }).click();
  await page.getByLabel("Code", { exact: true }).fill("ENG");
  await page.getByLabel("Name", { exact: true }).fill("Engineering");
  await page.getByRole("button", { name: "Save record" }).click();
  await expect(
    page.getByRole("cell", { name: "Engineering", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "People", exact: true }).click();
  await page.getByRole("button", { name: "Add employee", exact: true }).click();
  await page.getByLabel("Employee number", { exact: true }).fill("P001");
  await page
    .getByLabel("Legal name", { exact: true })
    .fill("Synthetic Employee");
  await page.getByLabel("Employment number", { exact: true }).fill("E001");
  await page.getByLabel("Start date", { exact: true }).fill("2026-01-01");
  await page
    .getByRole("combobox", { name: "Department", exact: true })
    .selectOption({ label: "Engineering" });
  await page.getByRole("button", { name: "Create draft", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Synthetic Employee" }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Activate employment", exact: true })
    .click();
  await page
    .getByLabel("Reason — avoid confidential personal details")
    .fill("Approved synthetic fixture");
  await page
    .getByRole("button", { name: "Confirm activate", exact: true })
    .click();
  await expect(page.getByText("active", { exact: true })).toBeVisible();
  await page.screenshot({
    path: "test-results/workforce-desktop.png",
    fullPage: true,
  });
  await page
    .getByRole("button", { name: "End employment", exact: true })
    .click();
  await page
    .getByLabel("Exclusive end date (day after last working day)")
    .fill("2026-02-01");
  await page
    .getByLabel("Reason — avoid confidential personal details")
    .fill("Synthetic relationship ended");
  await page.getByRole("button", { name: "Confirm end", exact: true }).click();
  await expect(page.getByText("ended", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: "Audit history", exact: true })
    .click();
  await expect(
    page.getByRole("cell", { name: "employment.end", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Permissions", exact: true }).click();
  await page.getByRole("button", { name: "New permission bundle", exact: true }).click();
  await page.getByLabel("Bundle name", { exact: true }).fill("Workforce reader");
  await page.getByRole("checkbox", { name: "View employee directory and employment history", exact: true }).check();
  await page.getByLabel("Bundle creation reason", { exact: true }).fill("Synthetic reusable access template");
  await page.getByRole("button", { name: "Save bundle", exact: true }).click();
  await expect(page.getByText("Bundle saved. Member permissions are unchanged.", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: "Edit permissions for Demo Colleague" })
    .click();
  await page.getByRole("combobox", { name: "Copy permission bundle", exact: true }).selectOption({ label: "Workforce reader" });
  await page
    .getByLabel("Reason — avoid confidential details")
    .fill("Synthetic company grant review");
  await page
    .getByRole("button", { name: "Review changes", exact: true })
    .click();
  await expect(
    page.getByRole("region", { name: "Permission change preview" }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Apply permissions", exact: true })
    .click();
  await expect(
    page.getByText("Permissions saved.", { exact: true }),
  ).toBeVisible();
  await page.getByText("Workforce reader", { exact: true }).click();
  await page.getByLabel("Archive reason for Workforce reader", { exact: true }).fill("Synthetic template retired");
  await page.getByRole("button", { name: "Archive Workforce reader", exact: true }).click();
  await expect(page.getByText("No active bundles.", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Edit permissions for Demo Colleague" }).click();
  await expect(page.getByRole("checkbox", { name: "View employee directory and employment history", exact: true })).toBeChecked();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await page.screenshot({
    path: "test-results/company-permissions.png",
    fullPage: true,
  });
  await page
    .getByRole("button", { name: "← All companies", exact: true })
    .click();
  await page.screenshot({
    path: "test-results/workspace-desktop.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(
    page.getByRole("heading", { name: "Demo Company A" }),
  ).toBeVisible();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth),
  ).toBeLessThanOrEqual(390);
  await page.screenshot({
    path: "test-results/workspace-mobile.png",
    fullPage: true,
  });
  const logoutResponse = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname === "/logout" &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  expect((await logoutResponse).status()).toBe(204);
  await expect(
    page.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
  expect(
    (
      await page.request.get("/api/v1/me", {
        headers: {
          Accept: "application/json",
          Origin: "http://localhost:8080",
        },
      })
    ).status(),
  ).toBe(401);
});
