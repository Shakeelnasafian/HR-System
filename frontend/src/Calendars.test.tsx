import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import { Calendars } from "./Calendars";
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
type Handler = (
  path: string,
  options: RequestInit,
) => Promise<Response> | undefined;
function stub(handler: Handler) {
  const fetcher = vi.fn((path: string, options: RequestInit) => {
    if (path === "/sanctum/csrf-cookie")
      return Promise.resolve(new Response(null, { status: 204 }));
    const response = handler(path, options);
    if (response) return response;
    throw new Error(`Unexpected request ${options.method} ${path}`);
  });
  vi.stubGlobal("fetch", fetcher);
  return fetcher;
}
const writes = (fetcher: ReturnType<typeof stub>) =>
  fetcher.mock.calls
    .filter(([path, options]) => path !== "/sanctum/csrf-cookie" && options.method !== "GET")
    .map(([path, options]) => ({
      path,
      method: options.method,
      body: JSON.parse(options.body as string),
    }));
const year = new Date().getFullYear();
const detail = (version: number, extra: object = {}) => ({
  data: {
    id: "cal",
    code: "STD",
    name: "Standard",
    archived: false,
    version,
    patterns: [
      { id: "p1", effective_from: "2025-01-01", working_days: [1, 2, 3, 4, 5] },
      { id: "p2", effective_from: "2026-01-01", working_days: [1, 2, 3, 4] },
    ],
    holidays: [],
    ...extra,
  },
});
const list = {
  data: [
    {
      id: "cal",
      code: "STD",
      name: "Standard",
      archived: false,
      version: 1,
      current_pattern: { effective_from: "2026-01-01", working_days: [1, 2, 3, 4] },
    },
    {
      id: "future",
      code: "NEW",
      name: "Next year",
      archived: false,
      version: 1,
      current_pattern: null,
    },
  ],
};

it("gates calendar and company settings tabs by capabilities", async () => {
  let held = ["organization.read"];
  stub((path) => {
    if (path.endsWith("/capabilities")) return json({ data: held });
    if (path.includes("/calendars?")) return json(list);
    if (path === base) return json({ data: { id: "company-a", name: "Company A", code: "A", timezone: "UTC", version: 1 } });
  });
  const { unmount } = render(
    <CompanyWorkspace tenant="t" company={{ id: "company-a", name: "Company A", code: "A" }} onBack={vi.fn()} />,
  );
  await userEvent.click(await screen.findByRole("button", { name: "Calendars" }));
  expect(screen.queryByRole("button", { name: "Company settings" })).not.toBeInTheDocument();
  expect(await screen.findByText("Mon, Tue, Wed, Thu")).toBeInTheDocument();
  expect(screen.getByText("No pattern in effect yet")).toBeInTheDocument();
  expect(screen.queryByRole("button", { name: "New calendar" })).not.toBeInTheDocument();
  unmount();

  held = ["company.manage"];
  render(
    <CompanyWorkspace tenant="t" company={{ id: "company-a", name: "Company A", code: "A" }} onBack={vi.fn()} />,
  );
  await userEvent.click(await screen.findByRole("button", { name: "Company settings" }));
  expect(screen.queryByRole("button", { name: "Calendars" })).not.toBeInTheDocument();
  expect(await screen.findByLabelText("Company code")).toHaveValue("A");
});

it("creates a calendar with exactly the chosen working days and no defaults", async () => {
  const fetcher = stub((path, options) => {
    if (options.method === "GET" && path.includes("/calendars?include_archived=0"))
      return json({ data: [] });
    if (options.method === "POST" && path === `${base}/calendars`)
      return json({ data: { id: "cal", code: "STD", name: "Standard", archived: false, version: 1 } }, 201);
  });
  render(<Calendars tenant="t" base={base} canWrite />);
  await userEvent.click(await screen.findByRole("button", { name: "New calendar" }));
  const days = within(screen.getByRole("group", { name: "Working days" }));
  for (const box of days.getAllByRole("checkbox")) expect(box).not.toBeChecked();
  expect(days.getAllByRole("checkbox")).toHaveLength(7);
  await userEvent.type(screen.getByLabelText("Calendar code"), "STD");
  await userEvent.type(screen.getByLabelText("Calendar name"), "Standard");
  await userEvent.type(screen.getByLabelText("Pattern effective from"), "2026-01-05");
  await userEvent.type(screen.getByLabelText("Reason — avoid confidential details"), "Initial setup");
  await userEvent.click(screen.getByRole("button", { name: "Create calendar" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("Choose at least one working day.");
  expect(writes(fetcher)).toHaveLength(0);

  await userEvent.click(days.getByRole("checkbox", { name: "Wednesday" }));
  await userEvent.click(days.getByRole("checkbox", { name: "Monday" }));
  await userEvent.click(screen.getByRole("button", { name: "Create calendar" }));
  expect(await screen.findByText("Calendar STD created.")).toBeInTheDocument();
  expect(writes(fetcher)).toEqual([
    {
      path: `${base}/calendars`,
      method: "POST",
      body: {
        code: "STD",
        name: "Standard",
        effective_from: "2026-01-05",
        working_days: [1, 3],
        reason: "Initial setup",
      },
    },
  ]);
});

it("keeps rename input on a 409 conflict and saves against the reloaded version", async () => {
  let version = 1,
    conflict = true;
  const fetcher = stub((path, options) => {
    if (path.includes("/calendars?")) return json(list);
    if (options.method === "GET" && path === `${base}/calendars/cal?year=${year}`)
      return json(detail(version));
    if (options.method === "PATCH" && path === `${base}/calendars/cal`) {
      if (conflict) {
        conflict = false;
        version = 2;
        return json({ message: "This record was changed." }, 409);
      }
      return json({ data: { ...detail(3).data, name: "Office week" } });
    }
  });
  render(<Calendars tenant="t" base={base} canWrite />);
  await userEvent.click(await screen.findByRole("button", { name: "Open STD" }));
  const name = await screen.findByLabelText("Calendar name");
  await userEvent.clear(name);
  await userEvent.type(name, "Office week");
  await userEvent.type(screen.getByLabelText("Reason for calendar change"), "Clearer name");
  await userEvent.click(screen.getByRole("button", { name: "Save calendar" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("changed by someone else");
  expect(screen.getByLabelText("Calendar name")).toHaveValue("Office week");
  expect(screen.getByLabelText("Reason for calendar change")).toHaveValue("Clearer name");

  await userEvent.click(screen.getByRole("button", { name: "Reload latest calendar" }));
  await waitFor(() =>
    expect(fetcher.mock.calls.filter(([p]) => p === `${base}/calendars/cal?year=${year}`)).toHaveLength(2),
  );
  expect(screen.getByLabelText("Calendar name")).toHaveValue("Office week");
  await userEvent.click(screen.getByRole("button", { name: "Save calendar" }));
  expect(await screen.findByText("Calendar details saved.")).toBeInTheDocument();
  expect(writes(fetcher).map((w) => w.body)).toEqual([
    { version: 1, reason: "Clearer name", name: "Office week", archived: false },
    { version: 2, reason: "Clearer name", name: "Office week", archived: false },
  ]);
  // Pattern history is newest first.
  const rows = screen.getAllByRole("row").map((r) => r.textContent);
  expect(rows.indexOf("2026-01-01Mon, Tue, Wed, Thu")).toBeLessThan(rows.indexOf("2025-01-01Mon, Tue, Wed, Thu, Fri"));
});

it("adds and removes holidays with the calendar version and a reason", async () => {
  let version = 3;
  let holidays: { id: string; holiday_date: string; name: string }[] = [];
  const fetcher = stub((path, options) => {
    if (path.includes("/calendars?")) return json(list);
    if (options.method === "GET" && path === `${base}/calendars/cal?year=${year}`)
      return json(detail(version, { holidays }));
    if (options.method === "POST" && path === `${base}/calendars/cal/holidays`) {
      const body = JSON.parse(options.body as string);
      holidays = [{ id: "h1", holiday_date: body.holiday_date, name: body.name }];
      version++;
      return json({ data: holidays[0] }, 201);
    }
    if (options.method === "DELETE" && path === `${base}/calendars/cal/holidays/h1`) {
      holidays = [];
      version++;
      return json({ data: { id: "h1", removed: true } });
    }
  });
  render(<Calendars tenant="t" base={base} canWrite />);
  await userEvent.click(await screen.findByRole("button", { name: "Open STD" }));
  expect(await screen.findByText(`No holidays recorded for ${year}.`)).toBeInTheDocument();
  await userEvent.type(screen.getByLabelText("Holiday date"), `${year}-05-01`);
  await userEvent.type(screen.getByLabelText("Holiday name"), "Founders Day");
  await userEvent.type(screen.getByLabelText("Reason for holiday"), "Company policy");
  await userEvent.click(screen.getByRole("button", { name: "Add holiday" }));
  const remove = await screen.findByRole("button", { name: `Remove Founders Day on ${year}-05-01` });
  expect(screen.getByText(`Holiday Founders Day on ${year}-05-01 added.`)).toBeInTheDocument();
  expect(screen.getByLabelText("Holiday name")).toHaveValue("");

  await userEvent.click(remove);
  await userEvent.type(screen.getByLabelText("Reason for removal"), "Entered in error");
  await userEvent.click(screen.getByRole("button", { name: "Confirm removal" }));
  expect(await screen.findByText(`Holiday Founders Day on ${year}-05-01 removed.`)).toBeInTheDocument();
  expect(await screen.findByText(`No holidays recorded for ${year}.`)).toBeInTheDocument();
  expect(writes(fetcher)).toEqual([
    {
      path: `${base}/calendars/cal/holidays`,
      method: "POST",
      body: { version: 3, reason: "Company policy", holiday_date: `${year}-05-01`, name: "Founders Day" },
    },
    {
      path: `${base}/calendars/cal/holidays/h1`,
      method: "DELETE",
      body: { version: 4, reason: "Entered in error" },
    },
  ]);
});

it("adds a pattern and links 422 field errors to the inputs", async () => {
  const fetcher = stub((path, options) => {
    if (path.includes("/calendars?")) return json(list);
    if (options.method === "GET" && path.startsWith(`${base}/calendars/cal?year=`))
      return json(detail(5));
    if (options.method === "POST" && path === `${base}/calendars/cal/patterns`)
      return json(
        { message: "Invalid", errors: { effective_from: ["A pattern already starts on this date."] } },
        422,
      );
  });
  render(<Calendars tenant="t" base={base} canWrite />);
  await userEvent.click(await screen.findByRole("button", { name: "Open STD" }));
  await userEvent.type(await screen.findByLabelText("New pattern effective from"), "2026-01-01");
  const group = within(screen.getByRole("group", { name: "Working days from that date" }));
  await userEvent.click(group.getByRole("checkbox", { name: "Saturday" }));
  await userEvent.type(screen.getByLabelText("Reason for new pattern"), "Weekend rota");
  await userEvent.click(screen.getByRole("button", { name: "Add pattern" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("A pattern already starts on this date.");
  const input = screen.getByLabelText("New pattern effective from");
  expect(input).toHaveAttribute("aria-invalid", "true");
  expect(input).toHaveAccessibleDescription("A pattern already starts on this date.");
  expect(writes(fetcher)[0].body).toEqual({
    version: 5,
    reason: "Weekend rota",
    effective_from: "2026-01-01",
    working_days: [6],
  });
});
