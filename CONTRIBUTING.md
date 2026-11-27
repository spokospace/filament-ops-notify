# Contributing

Issues and pull requests are welcome. Run `composer test` and `composer analyse` before opening a
pull request, and describe the problem the change solves, not only the change.

## Design principles

Every change to the package must keep these. A pull request that breaks one is closed, however
small it is.

- **Universal.** The package serves any Laravel or Filament app. No code, defaults or special
  cases for one installation. A problem that shows up in one app is usually that app's
  configuration, so check its Settings and the log's **Event** column before changing the package.
- **Settings are in charge.** Routing, title rules, topics and templates are what the admin set
  in Settings (or config/.env, which locks a field). Code must not override or quietly bypass
  them. A new option goes into Settings and config, with the same precedence as the existing
  ones.
- **No code in the app.** Bell notifications and Laravel notifications are forwarded without the
  app knowing the package exists. Don't add features that need apps to put package-specific keys
  or calls into their own code to get a result Settings can already give.
- **Failures stay contained.** A Telegram, cache or database problem must never break the app's
  own notification, request or job.

## Workflow

- Branch from `main` (`feat/...`, `fix/...`, `docs/...`). Commits to `main` are blocked.
- One pull request per change, with tests for new behaviour and docs in `docs/` and the README
  when behaviour or Settings change.
- Releases are tagged from `main` with `gh release create`.
