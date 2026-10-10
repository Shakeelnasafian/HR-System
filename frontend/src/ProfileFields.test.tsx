import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import { ProfileFieldSettings } from "./ProfileFields";
import { CompanyWorkspace } from "./Workforce";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
const base = "/api/v1/companies/company-a";
const json = (data: unknown, status = 200) =>
  Promise.resolve(
    new Response(JSON.stringify(data), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
const available = ["birth_date", "nationality", "personal_email", "personal_phone", "address", "emergency_contacts"];

it("saves exactly the selected profile fields with version and reason", async () => {
  const bodies: unknown[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn((path: string, options: RequestInit) => {
      if (path === "/sanctum/csrf-cookie") return Promise.resolve(new Response(null, { status: 204 }));
      if (path === `${base}/profile-fields` && options.method === "GET")
        return json({ data: { enabled: ["personal_email"], available, version: 1 } });
      if (path === `${base}/profile-fields` && options.method === "PUT") {
        const body = JSON.parse(options.body as string);
        bodies.push(body);
        return json({ data: { enabled: body.enabled, available, version: 2 } });
      }
      throw new Error(`Unexpected request ${options.method} ${path}`);
    }),
  );
  render(<ProfileFieldSettings tenant="t" base={base} />);
  const group = await screen.findByRole("group", { name: "Private profile fields this company collects" });
  expect(group).toHaveAccessibleDescription(/Nothing is collected until a field is selected/);
  const boxes = within(group).getAllByRole("checkbox");
  expect(boxes).toHaveLength(6);
  expect(boxes.filter((b) => (b as HTMLInputElement).checked)).toEqual([within(group).getByLabelText("Personal email")]);

  await userEvent.click(within(group).getByLabelText("Home address"));
  await userEvent.click(within(group).getByLabelText("Nationality"));
  await userEvent.click(within(group).getByLabelText("Personal email"));
  await userEvent.type(screen.getByLabelText("Reason for change — avoid confidential details"), "Data minimisation review");
  await userEvent.click(screen.getByRole("button", { name: "Save profile fields" }));
  expect(await screen.findByText("Profile field settings saved.")).toHaveAttribute("aria-live", "polite");
  expect(bodies).toEqual([{ version: 1, reason: "Data minimisation review", enabled: ["nationality", "address"] }]);
  expect(within(group).getByLabelText("Nationality")).toBeChecked();
});

it("offers employment types as an organization kind", async () => {
  const fetcher = vi.fn((path: string) => {
    if (path.endsWith("/capabilities")) return json({ data: ["organization.read", "organization.write"] });
    if (path.startsWith(`${base}/organization/`))
      return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
    throw new Error("Unexpected request " + path);
  });
  vi.stubGlobal("fetch", fetcher);
  render(<CompanyWorkspace tenant="t" company={{ id: "company-a", name: "Company A", code: "A" }} onBack={vi.fn()} />);
  await userEvent.click(await screen.findByRole("button", { name: "Organization" }));
  const kind = await screen.findByLabelText("Organization type");
  await userEvent.selectOptions(kind, "Employment types");
  expect(await screen.findByRole("heading", { name: "Add employment type" })).toBeInTheDocument();
  expect(await screen.findByText("No employment types yet.")).toBeInTheDocument();
  expect(fetcher).toHaveBeenCalledWith(`${base}/organization/employment_types?page=1`, expect.anything());
});
