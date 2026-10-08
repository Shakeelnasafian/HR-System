# Architecture preparation review record

Date: 7 October 2026.

## Authorization and provenance

The owner supplied `Shakeelnasafian/HR-System` and the V1 requirements. The owner approved documentation/architecture preparation, dependency investigation, data relationships, permission matrix, tenant-isolation design and foundation backlog. A subsequent explicit instruction requires an API-based SPA without Inertia. That instruction is reflected throughout the proposal.

The original requirements file is retained byte-for-byte under `docs/requirements/`. Its draft status is deliberately preserved: authorization to prepare these documents does not approve every unresolved business decision or authorize application implementation.

## Verified findings

- GitHub returned an empty repository during inspection; visibility was public and the connection had write permission. There was no application source or AGENTS.md to inspect.
- The complete supplied requirements were read. No Company Tools repository or source was supplied or inspected.
- Official Laravel release, starter-kit, Sanctum and Fortify documentation was consulted. The React starter-kit Composer/npm manifests were inspected through GitHub. Inertia coupling rules that starter kit out for this project.
- Official PHP, Node, PostgreSQL and Redis support/license information was consulted. Version families are proposals; no dependency graph was installed or resolved.
- Frappe HR was compared at official repository/documentation level only. Spatie permission main-branch manifest was inspected; stable-tag fit and integration have not been tested.

Sources and candidate decisions are in [dependency evaluation](../architecture/dependencies.md). Technical security assertions remain hypotheses until the [isolation spike](../architecture/isolation-validation.md) is executed.

## Documentation checks

Completed local checks: all relative Markdown links resolve; the original requirements are byte-for-byte unchanged; fenced blocks are balanced; all F00–F10 and ISO-01–ISO-15 items are present. The architecture, API contract, dependency decision and backlog consistently specify a standalone API-based SPA. Publication must be verified against these prepared files.

Original requirements SHA-256: `89051fe79ea0687ebf8b98e9bb5f4b048860eccb74ddbb2a0f07420e9937996b`.

These checks are documentation integrity checks only. No framework installation, application build, migration, browser test, RLS test, worker test, load test or deployment was performed. Mermaid source is included for GitHub rendering; no visual renderer validation is claimed.

## Review decisions requested

1. Accept the proposed Laravel 13/PHP 8.5 API plus independent React/TypeScript SPA baseline, subject to stable dependency resolution.
2. Accept the same-origin deployment and Sanctum/Fortify session/MFA proposal.
3. Accept shared PostgreSQL 18 with application company/field policies and tenant RLS contingent on spike success.
4. Confirm independent custom build versus providing Company Tools for a reuse review; existing products have not been ruled out by a fit-gap prototype.
5. Select the Redis licensing/deployment basis and confirm company/legal-entity, employment concurrency and English V1 defaults.
6. Approve the first executable increment F00–F04 when ready. Later business policy, hosting and retention decisions remain owned gates.

No new paid service, application license, HR statutory preset or production promise is introduced by these documents.
