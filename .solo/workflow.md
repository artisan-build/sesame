# Workflow — {{FILL: project name}}

Project profile for the `multi-agent-build` skill and for any agent working in this repo. The
coordinator agent reads this FIRST. Keep it truthful as the project evolves.

> **Scaffolded from the `artisan-build/laravel-nodeless` starter kit.** This repo is NOT the starter
> kit — never open a PR against `artisan-build/laravel-nodeless` from here.
>
> Replace every `{{FILL: ...}}` marker below before dispatching any agent. **Ship details** (PR
> target repo) and **Plan & coordination** (Solo project) are the two that cause real damage if left
> wrong — an agent will happily open a PR against the wrong repository. Delete this blockquote once
> the file is accurate.

## What this is

{{FILL: a short description of the project — same substance as the top of CLAUDE.md. Include the
non-goals; they are the most useful thing a coordinator can know.}}

## Phase & mode
- phase: {{FILL: greenfield (nothing shipped yet) | building | launched}}
- default mode: {{FILL: A-autonomous | B-human-merges}}
- merge_policy: {{FILL: e.g. merge when CI green; no human PR review required}}
- merge method: {{FILL: e.g. `gh pr merge --squash --auto` — and note whether auto-delete-branch is
  off on the repo, in which case branches must be deleted manually}}

## Hard gate (must be green before review; verified on the committed SHA, clean tree)
- command: `composer ready` (ide-helper regen + rector + pint + phpstan + pest + composer audit)
- extra suites: {{FILL: none, or list them}}
- monorepo: {{FILL: no | yes — describe the package layout}}

## CI (the merge gate for Mode A)
- status: {{FILL: verified | unverified — verify before gating merges on it}}
- minimum bar: CI MUST include (1) testing and (2) static analysis. If it doesn't, don't gate merges
  on CI (Mode A) until fixed.
- workflows/jobs: `.github/workflows/tests.yml` — `composer stan` (PHPStan/Larastan level 6) then
  `./vendor/bin/pest`, PHP 8.5 matrix; `.github/workflows/lint.yml` — `composer lint` (Pint).
  Inherited from the starter kit; this already meets the testing + static analysis bar.
- The inherited workflows install free `livewire/flux` and do not configure Flux Pro credentials.
  Flux Pro is optional: a project deliberately opts in by running `php artisan flux:pro`, then adds
  its own CI authentication step and repo secrets. Projects that have not opted in need no Flux
  credentials.

## Dependency install (fresh worktree)
- command: `composer install --no-interaction --prefer-dist`
- post-install: `cp .env.example .env && php artisan key:generate`
- tests use in-memory sqlite via `phpunit.xml`; `touch database/database.sqlite` only if running
  artisan commands that hit the DB.

## Harness map (role -> runtime; decorrelate model lineages)
- implementer: {{FILL: runtime + Solo agent_tool_id}}
- quality reviewer: {{FILL: runtime + Solo agent_tool_id — use a different model lineage}}
- acceptance judge: {{FILL: runtime + Solo agent_tool_id — different again}}

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
- PR target repo: {{FILL: owner/repo — REQUIRED, and note if private}}
- release / split steps: {{FILL: none, or describe}}

## Plan & coordination
- plan location: {{FILL: per-build Solo scratchpad (no standing PRD) | path to a standing PRD}}
- Solo project: {{FILL: `name` (id) — REQUIRED}}
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
- {{FILL: project-specific quirks, conventions, and standing preferences — testing style, enum
  preferences, things a reviewer keeps flagging. Delete this line if there are none yet.}}
