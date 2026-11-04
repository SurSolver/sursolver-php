# Changelog

## 0.1.0 — unreleased

First release. Tested against the SDK specification revision in `SPEC_VERSION` (catalog of
2026-11-04).

- `SurSolver\Client` with `createTask()`, `getTaskResult()`, `getBalance()`, `listTaskTypes()`,
  `listServices()` and `solve()` (3 s polling, 210 s deadline, `onTaskCreated`).
- Typed exceptions in `SurSolver\Exception`: `AuthenticationException`, `InvalidTaskException`
  (with `$supportedUserAgents`), `InsufficientBalanceException`, `NoSlotAvailableException`,
  `TaskNotFoundException`, `TaskFailedException`, `ApiException` (with `$errorCode`),
  `TransportException` (with `mayHaveCreatedTask()`), `ProtocolException`,
  `SolveTimeoutException`, `ConfigurationException`.
- `TaskTypes` and `ErrorCodes` constants generated from the public catalog.
- PHP 8.3+, ext-curl; no Composer dependencies.
