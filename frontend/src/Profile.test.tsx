import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import { CompanyWorkspace } from "./Workforce";
import { ProfilePanel } from "./Profile";

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
const person = {
  id: "person",
  employee_number: "P001",
  legal_name: "Synthetic Person",
  preferred_name: null,
};
function stub(caps: string[], profile: object) {
  const fetcher = vi.fn((path: string, options: RequestInit) => {
    if (path === "/sanctum/csrf-cookie")
      return Promise.resolve(new Response(null, { status: 204 }));
    if (path.endsWith("/capabilities")) return json({ data: caps });
    if (path.includes("/employees?"))
      return json({ data: [person], meta: { current_page: 1, last_page: 1, total: 1 } });
    if (path === `${base}/employees/person`)
      return json({ data: { employee: person, employments: [] } });
    if (path === `${base}/employees/person/profile` && options.method === "GET")
      return json({ data: profile });
    throw new Error(`Unexpected request ${options.method} ${path}`);
  });
  vi.stubGlobal("fetch", fetcher);
  return fetcher;
}
const profileCalls = (fetcher: ReturnType<typeof stub>) =>
  fetcher.mock.calls.filter(([path]) => String(path).endsWith("/profile"));

it("loads the private profile only on demand and shows only enabled fields", async () => {
  const fetcher = stub(["workforce.read", "profile.read"], {
    employee_id: "person",
    version: 1,
    fields: {
      personal_email: "synthetic@example.test",
      emergency_contacts: [{ name: "Contact One", relationship: "Sibling", phone: "+100" }],
    },
  });
  render(<CompanyWorkspace tenant="t" company={{ id: "company-a", name: "Company A", code: "A" }} onBack={vi.fn()} />);
  await userEvent.click(await screen.findByRole("button", { name: "View P001" }));
  const show = await screen.findByRole("button", { name: "Show private profile" });
  expect(show).toHaveAttribute("aria-expanded", "false");
  expect(screen.getByText(/Viewing this profile is recorded in the audit history/)).toBeInTheDocument();
  expect(profileCalls(fetcher)).toHaveLength(0);

  await userEvent.click(show);
  expect(await screen.findByText("synthetic@example.test")).toBeInTheDocument();
  expect(profileCalls(fetcher)).toHaveLength(1);
  expect(screen.getByText("Personal email")).toBeInTheDocument();
  expect(screen.getByText("Contact One (Sibling) · +100")).toBeInTheDocument();
  for (const hidden of ["Date of birth", "Nationality", "Personal phone", "Home address"])
    expect(screen.queryByText(hidden)).not.toBeInTheDocument();
  // No profile.write: read-only.
  expect(screen.queryByRole("button", { name: "Edit private profile" })).not.toBeInTheDocument();

  await userEvent.click(screen.getByRole("button", { name: "Hide private profile" }));
  expect(screen.queryByText("synthetic@example.test")).not.toBeInTheDocument();
});

it("hides the profile panel without profile.read", async () => {
  const fetcher = stub(["workforce.read", "workforce.write"], {});
  render(<CompanyWorkspace tenant="t" company={{ id: "company-a", name: "Company A", code: "A" }} onBack={vi.fn()} />);
  await userEvent.click(await screen.findByRole("button", { name: "View P001" }));
  await screen.findByText("Synthetic Person");
  expect(screen.queryByRole("button", { name: "Show private profile" })).not.toBeInTheDocument();
  expect(profileCalls(fetcher)).toHaveLength(0);
});

it("sends only changed enabled fields, nulls for cleared values and the contact list", async () => {
  let saved: Record<string, unknown> | null = null;
  const fetcher = vi.fn((path: string, options: RequestInit) => {
    if (path === "/sanctum/csrf-cookie")
      return Promise.resolve(new Response(null, { status: 204 }));
    if (path === `${base}/employees/person/profile` && options.method === "GET")
      return json({
        data: saved
          ? {
              employee_id: "person",
              version: 3,
              fields: {
                birth_date: "1990-01-02",
                personal_phone: null,
                emergency_contacts: [{ name: "New Contact", relationship: "Friend", phone: "+400" }],
              },
            }
          : {
              employee_id: "person",
              version: 2,
              fields: {
                birth_date: "1990-01-02",
                personal_phone: "+200",
                emergency_contacts: [{ name: "Old Contact", relationship: "Parent", phone: "+300" }],
              },
            },
      });
    if (path === `${base}/employees/person/profile` && options.method === "PATCH") {
      saved = JSON.parse(options.body as string);
      // The write response lists changed keys only, never values.
      return json({ data: { employee_id: "person", version: 3, updated: ["personal_phone", "emergency_contacts"] } });
    }
    throw new Error(`Unexpected request ${options.method} ${path}`);
  });
  vi.stubGlobal("fetch", fetcher);
  render(<ProfilePanel tenant="t" base={base} employeeId="person" canWrite />);
  expect(fetcher).not.toHaveBeenCalled();
  await userEvent.click(screen.getByRole("button", { name: "Show private profile" }));
  await userEvent.click(await screen.findByRole("button", { name: "Edit private profile" }));

  expect(screen.getByLabelText("Date of birth")).toHaveValue("1990-01-02");
  expect(screen.queryByLabelText("Nationality")).not.toBeInTheDocument();
  await userEvent.clear(screen.getByLabelText("Personal phone"));

  const contacts = screen.getByRole("group", { name: "Emergency contacts" });
  await userEvent.click(within(contacts).getByRole("button", { name: "Add emergency contact" }));
  const second = screen.getByRole("group", { name: "Emergency contact 2" });
  await userEvent.type(within(second).getByLabelText("Name"), "New Contact");
  await userEvent.type(within(second).getByLabelText("Relationship"), "Friend");
  await userEvent.type(within(second).getByLabelText("Phone"), "+400");
  await userEvent.click(screen.getByRole("button", { name: "Remove emergency contact 1" }));
  // Up to five contacts.
  const add = within(contacts).getByRole("button", { name: "Add emergency contact" });
  for (let i = 0; i < 4; i++) await userEvent.click(add);
  expect(add).toBeDisabled();
  for (let i = 5; i > 1; i--)
    await userEvent.click(screen.getByRole("button", { name: `Remove emergency contact ${i}` }));

  await userEvent.type(screen.getByLabelText("Reason for change — avoid confidential details"), "Employee request");
  await userEvent.click(screen.getByRole("button", { name: "Save private profile" }));
  expect(await screen.findByText("Private profile saved.")).toHaveAttribute("aria-live", "polite");
  expect(saved).toEqual({
    version: 2,
    reason: "Employee request",
    fields: {
      personal_phone: null,
      emergency_contacts: [{ name: "New Contact", relationship: "Friend", phone: "+400" }],
    },
  });
  // Values are re-read with profile.read after the save.
  expect(await screen.findByText("New Contact (Friend) · +400")).toBeInTheDocument();
  expect(fetcher.mock.calls.filter(([p, o]) => p.endsWith("/profile") && o.method === "GET")).toHaveLength(2);
});

it("associates 422 errors with profile inputs", async () => {
  const fetcher = vi.fn((path: string, options: RequestInit) => {
    if (path === "/sanctum/csrf-cookie")
      return Promise.resolve(new Response(null, { status: 204 }));
    if (options.method === "GET")
      return json({ data: { employee_id: "person", version: 0, fields: { personal_email: null } } });
    // No profile row yet: the first save sends version 0.
    expect(JSON.parse(options.body as string)).toEqual({ version: 0, reason: "Fix", fields: { personal_email: "x@example.test" } });
    return json({ message: "Invalid", errors: { "fields.personal_email": ["Enter a valid email."] } }, 422);
  });
  vi.stubGlobal("fetch", fetcher);
  render(<ProfilePanel tenant="t" base={base} employeeId="person" canWrite />);
  await userEvent.click(screen.getByRole("button", { name: "Show private profile" }));
  await userEvent.click(await screen.findByRole("button", { name: "Edit private profile" }));
  await userEvent.type(screen.getByLabelText("Personal email"), "x@example.test");
  await userEvent.type(screen.getByLabelText("Reason for change — avoid confidential details"), "Fix");
  await userEvent.click(screen.getByRole("button", { name: "Save private profile" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("Check the highlighted fields.");
  expect(screen.getByLabelText("Personal email")).toHaveAttribute("aria-invalid", "true");
  expect(screen.getByLabelText("Personal email")).toHaveAccessibleDescription("Enter a valid email.");
});
