// Shared test timing: jsdom suites run in parallel and typing-heavy flows can exceed
// the library defaults on slower machines. Import for its side effects from test files.
import { configure } from "@testing-library/react";
import { vi } from "vitest";

configure({ asyncUtilTimeout: 5000 });
vi.setConfig({ testTimeout: 30000 });
