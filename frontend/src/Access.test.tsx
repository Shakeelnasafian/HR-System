import { afterEach, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@testing-library/jest-dom/vitest";
import { Access } from "./Access";
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
const state = {
  data: [
    {
      id: "member",
      name: "Colleague",
      email: "colleague@example.test",
      status: "active",
      requires_mfa: true,
      permissions: ["company.read", "audit.read"],
    },
  ],
  actor_membership_id: "admin",
  access_version: 4,
  meta: { current_page: 1, last_page: 1, total: 1 },
  catalog: [
    { permission: "company.read", label: "View company", delegable: true },
    { permission: "workforce.read", label: "View workforce", delegable: true },
    { permission: "audit.read", label: "Read audit", delegable: false },
    { permission: "payroll.manage", label: "Manage payroll", delegable: false },
  ],
};
const json = (v: unknown) =>
  Promise.resolve(
    new Response(JSON.stringify(v), {
      headers: { "Content-Type": "application/json" },
    }),
  );
function stubApi(
  bundles: () => unknown[],
  write: (path: string, options: RequestInit) => unknown,
) {
  vi.stubGlobal(
    "fetch",
    vi.fn((path: string, options: RequestInit) => {
      if (path === "/sanctum/csrf-cookie")
        return Promise.resolve(new Response(null, { status: 204 }));
      if (options.method === "GET" && path.endsWith("/permission-bundles"))
        return json({ data: bundles() });
      if (options.method === "GET" && path.includes("/access?page="))
        return json(state);
      if (options.method === "PUT" || options.method === "POST")
        return json(write(path, options));
      throw new Error("Unexpected request " + path);
    }),
  );
}
const checkbox = (name: string) => screen.getByRole("checkbox", { name });
async function openEditor() {
  render(<Access tenant="tenant" base="/api/v1/companies/company" />);
  await userEvent.click(
    await screen.findByRole("button", {
      name: "Edit permissions for Colleague",
    }),
  );
}
it("preserves grants outside actor authority and requires a preview before saving", async () => {
  let writes = 0;
  stubApi(
    () => [],
    (_path, options) => {
      writes++;
      expect(options.method).toBe("PUT");
      expect(JSON.parse(options.body as string)).toEqual({
        version: 4,
        reason: "Approved grant",
        permissions: ["company.read", "audit.read", "workforce.read"],
      });
      return { data: {} };
    },
  );
  await openEditor();
  expect(checkbox("Read audit (outside your authority)")).toBeDisabled();
  expect(checkbox("Read audit (outside your authority)")).toBeChecked();
  await userEvent.click(checkbox("View workforce"));
  expect(checkbox("View workforce")).toBeChecked();
  await userEvent.type(
    screen.getByLabelText("Reason — avoid confidential details"),
    "Approved grant",
  );
  await userEvent.click(screen.getByRole("button", { name: "Review changes" }));
  expect(writes).toBe(0);
  expect(
    screen.getByRole("region", { name: "Permission change preview" }),
  ).toBeInTheDocument();
  await userEvent.click(
    screen.getByRole("button", { name: "Apply permissions" }),
  );
  await screen.findByText("Permissions saved.");
  expect(writes).toBe(1);
});
it("copies a bundle only when Add is clicked, adding delegable permissions and announcing them", async () => {
  stubApi(
    () => [
      {
        id: "reader",
        name: "Reader",
        permissions: ["company.read", "workforce.read", "payroll.manage"],
        delegable: true,
      },
      {
        id: "payroll",
        name: "Payroll",
        permissions: ["payroll.manage"],
        delegable: false,
      },
    ],
    () => {
      throw new Error("Unexpected write");
    },
  );
  await openEditor();
  const select = screen.getByLabelText("Copy permission bundle");
  const add = screen.getByRole("button", { name: "Add bundle permissions" });
  expect(add).toBeDisabled();
  expect(
    screen.getByRole("option", { name: "Payroll (outside your authority)" }),
  ).toBeDisabled();

  await userEvent.selectOptions(select, "reader");
  expect(select).toHaveValue("reader");
  expect(checkbox("View workforce")).not.toBeChecked();
  expect(
    checkbox("Manage payroll (outside your authority)"),
  ).not.toBeChecked();
  expect(add).toBeEnabled();

  await userEvent.click(add);
  expect(checkbox("View company")).toBeChecked();
  expect(checkbox("View workforce")).toBeChecked();
  expect(checkbox("Read audit (outside your authority)")).toBeChecked();
  expect(
    checkbox("Manage payroll (outside your authority)"),
  ).not.toBeChecked();
  expect(screen.getByText("Added from Reader: View workforce.")).toHaveAttribute(
    "aria-live",
    "polite",
  );
  expect(select).toHaveValue("");
  expect(add).toBeDisabled();

  await userEvent.selectOptions(select, "reader");
  await userEvent.click(add);
  expect(
    screen.getByText("Reader added no new permissions."),
  ).toBeInTheDocument();
});
it("reports archiving a bundle without claiming it was saved", async () => {
  let active = true;
  stubApi(
    () =>
      active
        ? [
            {
              id: "reader",
              name: "Reader",
              permissions: ["company.read"],
              delegable: true,
            },
          ]
        : [],
    (path, options) => {
      expect(path).toBe(
        "/api/v1/companies/company/permission-bundles/reader/archive",
      );
      expect(JSON.parse(options.body as string)).toEqual({ reason: "Retired" });
      active = false;
      return { data: {} };
    },
  );
  render(<Access tenant="tenant" base="/api/v1/companies/company" />);
  await userEvent.click(await screen.findByText("Reader"));
  await userEvent.type(
    screen.getByLabelText("Archive reason for Reader"),
    "Retired",
  );
  await userEvent.click(screen.getByRole("button", { name: "Archive Reader" }));
  expect(
    await screen.findByText(
      "Bundle archived. Member permissions are unchanged.",
    ),
  ).toBeInTheDocument();
  expect(
    screen.queryByText("Bundle saved. Member permissions are unchanged."),
  ).not.toBeInTheDocument();
  expect(await screen.findByText("No active bundles.")).toBeInTheDocument();
});
