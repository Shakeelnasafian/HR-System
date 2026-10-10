import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
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
const person = { id: "person", employee_number: "P001", legal_name: "Synthetic Person", preferred_name: null };
const boss = { id: "boss", employee_number: "P003", legal_name: "New Manager", preferred_name: null };
const job = {
  id: "job",
  employment_number: "E001",
  start_date: "2026-01-01",
  end_date: null,
  status: "active",
  version: 4,
  probation_end_date: null,
  current_assignment: {
    id: "a1",
    effective_from: "2026-01-01",
    department: { id: "d1", code: "SAL", name: "Sales" },
    location: { id: "l1", name: "Berlin" },
    position: null,
    employment_type: { id: "t1", name: "Full time" },
    calendar: { id: "c1", name: "Standard" },
    manager: { employment_id: "mjob", employment_number: "M001", employee_id: "old", employee_number: "P002", legal_name: "Old Manager", preferred_name: null },
    reason: "Initial assignment",
    created_at: "2026-01-01T00:00:00Z",
  },
};
const org = (rows: object[]) => json({ data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } });
const row = (id: string, name: string, archived = false) => ({ id, code: id, name, archived, version: 1 });

function stub() {
  const fetcher = vi.fn((path: string, options: RequestInit) => {
    const method = options.method;
    if (path === "/sanctum/csrf-cookie") return Promise.resolve(new Response(null, { status: 204 }));
    if (path.endsWith("/capabilities"))
      return json({ data: ["workforce.read", "workforce.write", "organization.read"] });
    if (path === `${base}/employees?page=1&q=`) return org([person]);
    if (path === `${base}/employees?page=1&q=P00`) return org([person, boss]);
    if (path === `${base}/employees/person`) return json({ data: { employee: person, employments: [job] } });
    if (path === `${base}/employees/boss`)
      return json({
        data: {
          employee: boss,
          employments: [
            { id: "old", employment_number: "B000", start_date: "2020-01-01", end_date: "2021-01-01", status: "ended", version: 1 },
            { id: "bossjob", employment_number: "B001", start_date: "2024-01-01", end_date: null, status: "active", version: 1 },
          ],
        },
      });
    if (path.startsWith(`${base}/organization/departments?per_page=100`))
      return org([row("d1", "Sales"), row("d2", "Support"), row("d3", "Closed dept", true)]);
    if (path.startsWith(`${base}/organization/locations?per_page=100`)) return org([row("l1", "Berlin")]);
    if (path.startsWith(`${base}/organization/positions?per_page=100`)) return org([row("p1", "Analyst")]);
    if (path.startsWith(`${base}/organization/employment_types?per_page=100`))
      return org([row("t1", "Full time"), row("t2", "Part time")]);
    if (path === `${base}/calendars?include_archived=0`)
      return json({ data: [{ id: "c1", code: "STD", name: "Standard", archived: false, version: 1 }] });
    if (path === `${base}/employments/job/assignments` && method === "GET")
      return json({ data: [{ ...job.current_assignment, employment_id: "job" }] });
    if (path === `${base}/employments/job/assignments` && method === "POST")
      return json({ data: { ...job.current_assignment, id: "a2", employment_version: 5 } }, 201);
    if (path === `${base}/employments/job` && method === "PATCH") return json({ data: { ...job, version: 5 } });
    throw new Error(`Unexpected request ${method} ${path}`);
  });
  vi.stubGlobal("fetch", fetcher);
  return fetcher;
}
const writes = (fetcher: ReturnType<typeof stub>) =>
  fetcher.mock.calls
    .filter(([path, options]) => path !== "/sanctum/csrf-cookie" && options.method !== "GET")
    .map(([path, options]) => ({ path, method: options.method, body: JSON.parse(options.body as string) }));

async function openDetail() {
  render(<CompanyWorkspace tenant="t" company={{ id: "company-a", name: "Company A", code: "A" }} onBack={vi.fn()} />);
  await userEvent.click(await screen.findByRole("button", { name: "View P001" }));
  await screen.findByText("E001");
}

it("shows the current assignment and loads history on request", async () => {
  const fetcher = stub();
  await openDetail();
  const card = screen.getByText("E001").closest("article")!;
  expect(within(card).getByText("Sales")).toBeInTheDocument();
  expect(within(card).getByText("Old Manager (P002)")).toBeInTheDocument();
  expect(within(card).getByText("Probation ends: Not set")).toBeInTheDocument();
  expect(fetcher.mock.calls.some(([p]) => String(p).endsWith("/assignments"))).toBe(false);
  await userEvent.click(within(card).getByRole("button", { name: "Show assignment history for E001" }));
  const table = await within(card).findByRole("table", { name: "Assignment history, newest first" });
  expect(within(table).getByText("Initial assignment")).toBeInTheDocument();
  expect(within(table).getByText("Old Manager (P002)")).toBeInTheDocument();
  expect(within(table).getByText("Sales")).toBeInTheDocument();
});

it("posts an effective-dated assignment with keep, clear and set semantics and a resolved manager", async () => {
  const fetcher = stub();
  await openDetail();
  await userEvent.click(screen.getByRole("button", { name: "Change assignment" }));
  const form = screen.getByRole("form", { name: "Change assignment: E001" });
  const department = within(form).getByLabelText("Department");
  await within(department).findByRole("option", { name: "Support" });
  expect(within(department).getByRole("option", { name: "Keep unchanged (currently Sales)" })).toBeInTheDocument();
  expect(within(department).queryByRole("option", { name: "Closed dept" })).not.toBeInTheDocument();
  expect(within(form).getByLabelText("Employment type")).toHaveValue("__keep");

  await userEvent.type(within(form).getByLabelText("Effective from"), "2026-11-01");
  await userEvent.selectOptions(department, "Support");
  await userEvent.selectOptions(within(form).getByLabelText("Location"), "Clear — no location");

  const manager = within(form).getByRole("group", { name: "Manager" });
  expect(within(manager).getByLabelText("Keep unchanged (currently Old Manager (P002))")).toBeChecked();
  await userEvent.click(within(manager).getByLabelText("Choose a new manager"));
  await userEvent.type(within(manager).getByLabelText("Search manager by name or employee number"), "P00{Enter}");
  expect(await within(manager).findByText("2 employees found.")).toHaveAttribute("aria-live", "polite");
  await userEvent.click(within(manager).getByRole("button", { name: "Select Synthetic Person (P001)" }));
  expect(within(manager).getByText("An employee cannot be their own manager.")).toBeInTheDocument();
  await userEvent.click(within(manager).getByRole("button", { name: "Select New Manager (P003)" }));
  expect(await within(manager).findByText("New Manager (P003) · B001")).toBeInTheDocument();

  await userEvent.type(within(form).getByLabelText("Reason — avoid confidential personal details"), "Team move");
  await userEvent.click(within(form).getByRole("button", { name: "Save assignment" }));
  expect(await screen.findByText("Assignment saved.")).toBeInTheDocument();
  expect(writes(fetcher)).toEqual([
    {
      path: `${base}/employments/job/assignments`,
      method: "POST",
      body: {
        version: 4,
        reason: "Team move",
        effective_from: "2026-11-01",
        department_id: "d2",
        location_id: null,
        manager_employment_id: "bossjob",
      },
    },
  ]);
});

it("clears the manager explicitly and refuses a no-op change", async () => {
  const fetcher = stub();
  await openDetail();
  await userEvent.click(screen.getByRole("button", { name: "Change assignment" }));
  const form = screen.getByRole("form", { name: "Change assignment: E001" });
  await userEvent.type(within(form).getByLabelText("Effective from"), "2026-11-01");
  await userEvent.type(within(form).getByLabelText("Reason — avoid confidential personal details"), "Reorg");
  await userEvent.click(within(form).getByRole("button", { name: "Save assignment" }));
  expect(await within(form).findByRole("alert")).toHaveTextContent("Change or clear at least one field");
  expect(writes(fetcher)).toEqual([]);

  await userEvent.click(within(form).getByLabelText("Clear — no manager"));
  await userEvent.click(within(form).getByRole("button", { name: "Save assignment" }));
  await screen.findByText("Assignment saved.");
  expect(writes(fetcher)).toEqual([
    {
      path: `${base}/employments/job/assignments`,
      method: "POST",
      body: { version: 4, reason: "Reorg", effective_from: "2026-11-01", manager_employment_id: null },
    },
  ]);
});

it("edits the probation end date with version and reason", async () => {
  const fetcher = stub();
  await openDetail();
  await userEvent.click(screen.getByRole("button", { name: "Edit probation end date" }));
  const form = screen.getByRole("form", { name: "Probation end date: E001" });
  const date = within(form).getByLabelText("Probation end date");
  expect(date).toHaveAccessibleDescription(/No date is calculated automatically/);
  await userEvent.type(date, "2026-06-30");
  await userEvent.type(within(form).getByLabelText("Reason — avoid confidential personal details"), "Contract terms");
  await userEvent.click(within(form).getByRole("button", { name: "Save probation date" }));
  await screen.findByText("Probation end date saved.");
  expect(writes(fetcher)).toEqual([
    {
      path: `${base}/employments/job`,
      method: "PATCH",
      body: { version: 4, reason: "Contract terms", probation_end_date: "2026-06-30" },
    },
  ]);
});
