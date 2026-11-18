# Workflow — Sesame

Project profile for the `multi-agent-build` skill and for any agent working in this repo. The
coordinator agent reads this FIRST. Keep it truthful as the project evolves.

## What this is

Sesame simulates Amazon SES for staging load runs as a standalone Scalpels app. It accepts SES v2
`SendEmail` requests with `Content.Raw`, selects a deterministic outcome for each recipient, and sends
SNS notifications signed in the real format to the consumer's webhook. It retains the ground truth
so the consumer can reconcile every event. Growth CRM is the first consumer.

Real delivery, rendering, inbox placement and deliverability are outside its scope. Never point
Sesame at real recipients or real SES. Production apps must refuse to boot with simulator config.

## Phase & mode
- phase: building
- default mode: A-autonomous
- merge_policy: merge when CI is green; no human PR review required
- merge method: `gh pr merge --squash`. Delete-branch-on-merge is off; delete branches manually
  after confirming the merge landed.

## Hard gate (must be green before review; verified on the committed SHA, clean tree)
- command: `composer ready` (ide-helper regen + rector + pint + phpstan + pest + composer audit)
- extra suites: none. `phpunit.xml` includes the Unit and Feature suites, both using Postgres
  (`pgsql`, database `sesame_test`); CI supplies a `postgres:18` service.
- monorepo: no. `app/Sesame` is the simulator. `app/CrmHarness` is a local CRM-compatible harness
  standing in for Growth; it is outside Sesame's contract and must not grow into a CRM.

## CI (the merge gate for Mode A)
- status: verified. Main branch protection requires exactly `ci (8.5)` and `quality`, matching
  the workflow jobs below.
- minimum bar: CI MUST include (1) testing and (2) static analysis. If it doesn't, don't gate merges
  on CI (Mode A) until fixed.
- workflows/jobs: `.github/workflows/tests.yml` (workflow `tests`, job `ci`, check `ci (8.5)`):
  `composer stan` (PHPStan/Larastan level 6), then `./vendor/bin/pest`, then `composer audit`,
  PHP 8.5 matrix with a `postgres:18` service and database `sesame_test`.
  `.github/workflows/lint.yml` (workflow `linter`, job/check `quality`): `composer lint` (Pint).
  Inherited from the starter kit; this already meets the testing + static analysis bar.
- The inherited workflows install free `livewire/flux` and do not configure Flux Pro credentials.
  Flux Pro is optional: a project deliberately opts in by running `php artisan flux:pro`, then adds
  its own CI authentication step and repo secrets. Projects that have not opted in need no Flux
  credentials.

## Dependency install (fresh worktree)
- command: `composer install --no-interaction --prefer-dist`
- post-install: `cp .env.example .env && php artisan key:generate`
- tests use Postgres via `phpunit.xml`, at `127.0.0.1` with database `sesame_test`.
  Use a disposable local database for worktree commands; no SQLite test database is configured.

## Harness map (role -> runtime)

Resolve implementer, quality reviewer and acceptance judge roles through
`~/Herd/brain/playbooks/resolve-agent-role.md` against `~/Herd/brain/agents.json`, the fleet-wide
authority. This profile does not restate runtime bindings or Solo tool ids.

## Toolchain conformance — the ride-along rule (STANDING, all projects)

Run the project's full conformance command (`composer ready`, or the stack equivalent) as part of
FINALIZING every PR, and **let whatever it changes ride along in that PR** as a single isolated
commit titled `composer ready`.

The point is that conforming to the current standard is **passive** rather than something anyone has
to remember. Tools like Rector exist to keep the codebase at the current standard continuously; if
their output only lands when someone thinks to run them, the codebase drifts and the eventual
catch-up is a huge unreviewable diff.

- **Do NOT open a separate branch for these changes.** As long as the tool CONFIGURATION is
  unchanged, the unrelated changes riding along on any given PR are small.
- **The one exception:** introducing a new Rector rule, or changing `pint.json` / equivalent tool
  config. That sweep is large and deliberate, so it gets its own dedicated branch and PR.
- Keep it in its OWN commit so a reviewer can separate "the feature" from "the sweep" at a glance.

## Ship details
- branch naming: `feat/<slug>` (`fix/<slug>` for fixes, `chore/<slug>` for maintenance)
- PR target repo: `artisan-build/sesame` (public)
- release / split steps: none

## Plan & coordination
- plan location: a per-build Solo scratchpad
- Solo project: `sesame` (132)
- run-log: per-build scratchpad named `<branch>-run-log`; coordinator appends at every transition

## Stack notes / quirks
- Laravel 13, Livewire 4, Flux 2, PHP ^8.3; CI exercises 8.5.
- **Nodeless by design: no Node, npm, Vite, or frontend build step. Do not introduce any.**
  Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`.
- **Adding new Tailwind classes requires regenerating that bundle** with `php artisan
  tailwind:optimize` (downloads the standalone Tailwind CLI, no Node) and **committing the result**.
  A class that is not in the committed bundle simply will not apply — silently. After regenerating,
  verify the class actually landed and eyeball the page; do not assume.
- **Delete `public/build/assets/app.css` before regenerating.** Tailwind v4 scans the committed CSS
  as one of its own sources, so stale/junk classes re-seed themselves forever if you regenerate in
  place.
- Never naively `grep` the built bundle to check for a class — it is minified and selectors are
  backslash-escaped. Strip backslashes and use `grep -F`, or you will get a false negative.
- `composer ready` regenerates IDE helper files. `_ide_helper.php` / `_ide_helper_models.php` stay
  COMMITTED on purpose — PHPStan `scanFiles` needs `_ide_helper_models.php` to resolve model types.
  `.phpstorm.meta.php` is gitignored (it embeds machine-specific absolute paths); the asymmetry is
  deliberate — do not "fix" it by ignoring all three or committing all three.
- PHPStan baseline lives in `phpstan-baseline.neon`; keep it shrinking, never grow it silently.
