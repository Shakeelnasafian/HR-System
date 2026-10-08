# HR Platform

A planned multi-tenant HR platform with multiple companies per tenant.

**Status: foundation implementation in review.** The owner approved continuing implementation on 8 October 2026. The repository now contains a Laravel API and a separate React/TypeScript SPA. Production deployment is not authorized or ready.

## Architecture direction

- Laravel modular monolith exposing a versioned JSON REST API.
- Independent React/TypeScript single-page application, built and routed separately from Laravel. **No Inertia.** This reflects the owner's explicit clarification on 7 October 2026.
- Proposed PostgreSQL tenant isolation, Redis background processing, and Docker deployment.
- Implemented first-party authentication: Sanctum sessions plus headless Fortify authentication/MFA, with SPA and API served through one origin initially.

The API-based SPA choice is confirmed. Versions, persistence details and remaining architecture recommendations are proposed until reviewed. The original requirements remain unchanged as a traceable baseline; the API-based SPA clarification governs frontend delivery.

## Review documents

| Document | Purpose |
|---|---|
| [V1 requirements](docs/requirements/HR_Platform_V1_Requirements.md) | Original scope, business rules and release obligations |
| [Architecture](docs/architecture/architecture.md) | Boundaries, deployment, decisions and open questions |
| [Dependency evaluation](docs/architecture/dependencies.md) | Official-source findings, alternatives and version candidates |
| [Logical data model](docs/architecture/data-model.md) | Relationships, ownership, history and integrity rules |
| [Permissions](docs/architecture/permissions.md) | Resource/action scopes and sensitive-access rules |
| [SPA and API contract](docs/architecture/api-contract.md) | Authentication, tenant context and API conventions |
| [Isolation validation](docs/architecture/isolation-validation.md) | Threat model and unexecuted RLS/worker spike |
| [Foundation backlog](docs/planning/foundation-backlog.md) | Ordered work, acceptance criteria and approval boundaries |
| [Review record](docs/planning/review-record.md) | Evidence, completed checks and unresolved decisions |

## Delivery boundary

V1 covers workforce, documents, leave, attendance, approvals, lifecycle tasks and reporting. Payroll calculation, AI, SaaS billing, native mobile apps and vendor-specific biometric integrations remain outside V1.

Start with the [foundation runbook](docs/planning/foundation-runbook.md) for local Docker setup, synthetic accounts, test commands and current limitations. Read the [implementation status](docs/planning/implementation-status.md) before treating any foundation phase as complete. Examples must use synthetic data; this is a public repository. No project license has been selected.
