import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import { CompanySettings } from "./CompanySettings";

const base = "/api/v1/companies/company-a";
const json = (data: unknown, status = 200) =>
  Promise.resolve(
    new Response(JSON.stringify(data), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
// Server-accepted identifiers; the current value "UTC" is deliberately absent.
const zones = ["America/New_York", "Asia/Karachi", "Asia/Kolkata", "Europe/Kyiv"];
function stub(
  patch: (body: Record<string, unknown>) => Promise<Response>,
  timezones: () => Promise<Response> = () => json({ data: zones }),
) {
  let company = { id: "company-a", name: "Company A", code: "A", timezone: "UTC", version: 3 };
  const bodies: Record<string, unknown>[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn((path: string, options: RequestInit) => {
      if (path === "/sanctum/csrf-cookie") return Promise.resolve(new Response(null, { status: 204 }));
      if (path === "/api/v1/timezones" && options.method === "GET") {
        expect((options.headers as Record<string, string>)["X-Tenant-ID"]).toBeUndefined();
        return timezones();
      }
      if (path === base && options.method === "GET") return json({ data: company });
      if (path === base && options.method === "PATCH") {
        const body = JSON.parse(options.body as string);
        bodies.push(body);
        return patch(body).then((r) => {
          if (r.ok) company = { ...company, ...body, version: company.version + 1 };
          return r;
        });
      }
      throw new Error("Unexpected request " + path);
    }),
  );
  return bodies;
}

it("saves only the changed timezone with version and reason, explaining its effect", async () => {
  const onSaved = vi.fn();
  const bodies = stub((body) =>
    json({ data: { id: "company-a", name: "Company A", code: "A", timezone: body.timezone, version: 4 } }),
  );
  render(<CompanySettings tenant="t" base={base} onSaved={onSaved} />);
  const zone = await screen.findByLabelText("Timezone");
  await waitFor(() => expect(zone).toBeEnabled());
  expect(screen.getByRole("option", { name: "Asia/Kolkata" })).toBeInTheDocument();
  expect(screen.queryByRole("option", { name: "Asia/Calcutta" })).not.toBeInTheDocument();
  // The current value is kept even when the browser list does not include it.
  expect(zone).toHaveValue("UTC");
  expect(zone).toHaveAccessibleDescription(/determines the company’s current date/);
  expect(screen.getByLabelText("Company code")).toHaveAttribute("readonly");
  await userEvent.selectOptions(zone, "Asia/Karachi");
  await userEvent.type(screen.getByLabelText("Reason for change — avoid confidential details"), "Head office moved");
  await userEvent.click(screen.getByRole("button", { name: "Save company settings" }));
  expect(await screen.findByText("Company settings saved.")).toHaveAttribute("aria-live", "polite");
  expect(bodies).toEqual([{ version: 3, reason: "Head office moved", timezone: "Asia/Karachi" }]);
  expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ timezone: "Asia/Karachi", version: 4 }));
});

it("keeps input on conflict and marks 422 field errors accessibly", async () => {
  let attempt = 0;
  const bodies = stub(() =>
    ++attempt === 1
      ? json({ message: "Stale version." }, 409)
      : json({ message: "Invalid", errors: { name: ["The name has already been taken."] } }, 422),
  );
  render(<CompanySettings tenant="t" base={base} />);
  const name = await screen.findByLabelText("Company name");
  await userEvent.clear(name);
  await userEvent.type(name, "Company Alpha");
  await userEvent.type(screen.getByLabelText("Reason for change — avoid confidential details"), "Rebrand");
  await userEvent.click(screen.getByRole("button", { name: "Save company settings" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("changed by someone else");
  await userEvent.click(screen.getByRole("button", { name: "Reload latest company" }));
  expect(screen.getByLabelText("Company name")).toHaveValue("Company Alpha");
  await userEvent.click(screen.getByRole("button", { name: "Save company settings" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("Check the highlighted fields.");
  expect(screen.getByLabelText("Company name")).toHaveAttribute("aria-invalid", "true");
  expect(screen.getByLabelText("Company name")).toHaveAccessibleDescription("The name has already been taken.");
  expect(bodies).toEqual([
    { version: 3, reason: "Rebrand", name: "Company Alpha" },
    { version: 3, reason: "Rebrand", name: "Company Alpha" },
  ]);
});

it("falls back to a text input when the timezone list cannot be loaded", async () => {
  stub(
    () => json({}),
    () => json({ message: "Unavailable" }, 503),
  );
  render(<CompanySettings tenant="t" base={base} />);
  await waitFor(() => expect(screen.getByLabelText("Timezone").tagName).toBe("INPUT"));
  const zone = screen.getByLabelText("Timezone");
  expect(zone).toHaveValue("UTC");
});
