# First-party Scalpels integration

Sesame uses `artisan-build/built-for-cloud` for the canonical human identity schema,
ownership, onboarding, delegated console authentication, credentials, and the application shell.
The app does not maintain a parallel Fortify user model or login implementation.

## MCP contract

`GET /bfc/meta` advertises `/mcp`, `mcp-serve`, `mcp-delegated` and
`mcp-effect-scoped`. The stateless MCP transport uses `bfc.mcp:product,read`:
installation MCP credentials and verified, short-lived Scalpels assertions can read it;
an owner-management token alone is not a product MCP credential.

Tools: `accounts`, `presets`, `runs`, `run_summary`, `run_truth`. All are content-classified,
read-only, and resolve the package acting principal. Authorized members of this installation
share its simulator data; simulated SES accounts are not human tenants. Lists default to 25
rows, with a maximum of 100 and keyset cursors. Truth can contain recipient addresses and
drawn outcomes. Accounts never return access key IDs (the simulator does not verify SigV4
signatures), private signing keys, truth/subscription tokens or webhook URLs. Simulator writes
remain the existing operator Artisan commands. The consumer truth-token API is unchanged.

## Existing-install upgrade (requires explicit deployment approval)

This is a forward-only PostgreSQL schema transition, not a safe unattended metadata update.
Do not deploy during a load run. Rehearse against a restored backup first.

1. Stop load generation, drain the instance's ingest buffer and queue, then enter maintenance
   mode and quiesce workers and all human/authentication writes. The upgrade is not safe to run
   concurrently with sign-ins or user changes. Take a recoverable database backup. Keep the
   previous code and environment configuration with the backup.
2. Verify there are no case-insensitive duplicate legacy user emails. Resolve any duplicates
   deliberately before migrating. An already mixed legacy/BFC schema requires operator review.
3. Set `SESSION_DRIVER=cookie` before booting the new release (BFC rejects database sessions).
   Keep the existing `APP_KEY`; rotating it here would invalidate unrelated encrypted data.
   Clear cached configuration as part of the approved rollout.
4. Install locked dependencies and run `php artisan migrate --force`. The early migration moves
   legacy `users`, `passkeys`, `sessions` and `password_reset_tokens` into the
   `sesame_legacy_auth` schema before the package creates its canonical tables. Rows, indexes,
   sequences and passkey foreign keys are preserved. Simulator and CRM harness tables are not
   rewritten. A late migration copies user IDs, email/name, verification dates, password hashes
   and timestamps to the package table, then advances its identity sequence.
5. All imported users are **members**, never inferred owners/admins. Users with a Fortify MFA
   secret or enrolled passkey are **deactivated**: the package does not implement those factors,
   so password-only sign-in must not silently bypass them. Review and re-enroll those people
   through the approved Scalpels identity flow before granting access. Old remember tokens,
   sessions and password reset links are not imported. Archive credentials remain sensitive;
   restrict access and decide retention separately. The archive is not automatically deleted.
6. Establish the authorized owner using the package ownership claim/onboarding process. A local
   standalone install can use the package's interactive `php artisan create-admin --local`;
   never infer ownership from the oldest legacy user. Delegated MCP identities are separate
   actors, not rows in `users` and not automatically installation owners.
7. Set the deployment-specific `BUILT_FOR_CLOUD_CONSOLE_ISSUER` and
   `BUILT_FOR_CLOUD_CONSOLE_AUDIENCE`, then enroll only Scalpels' public verification key through
   the authenticated package provisioning/re-key flow. Do not put private assertion-signing
   keys in Sesame. Missing trust configuration fails closed; declaring MCP in a manifest does
   not establish trust. Preserve existing owner credentials and use the approved claim flow
   if no owner credential exists.
8. Separately apply the reviewed Scalpels catalog/installation transition. The catalog app and
   each existing deployment have distinct kinds; editing a listing does not change deployments.
   After a successful metadata probe, Scalpels also needs a stored owner token, a running BFC
   deployment, delegated capability and the `/mcp` endpoint. Refresh capabilities through the
   normal authenticated installation flow. Verify `installed_apps`, `app_tools`, and a real
   app tool call with the intended Scalpels account before declaring that installation ready.

Migration `down()` refuses to erase the archive or canonical identities. Rollback means restoring
the pre-upgrade database, code and configuration together. Do not run `migrate:fresh` or attempt
to reverse just one of these migrations on an existing installation. Migration history makes a
completed upgrade a no-op on subsequent `migrate` runs; a partial upgrade must be completed or
restored, not served to users halfway through.

## Runtime and maintenance

Keep the managed queue, generated APP_KEY for **new** installations, no persistent ingest disk,
hibernation and push-to-deploy disabled. Do not enable Cloud's scheduler for Sesame. Simulator
delivery/pruning still uses requests and delayed queue jobs. BFC registers an optional hourly
credential-authorization tombstone cleanup; run `php artisan bfc:credential-authorizations:prune
--local` during approved maintenance between load runs instead. Deferred cleanup is storage
maintenance, not permission to accept expired authorization.

## Verification

`composer ready` covers the package contract, guarded MCP transport, actual delegated assertions
(including replay, audience and inactive-actor refusal), list bounds/credential redaction,
and the existing simulator suite. Legacy upgrade tests use disposable PostgreSQL databases and
the original scaffold migrations to verify the complete migration sequence without touching a
live installation. Local checks do not prove a production installation is claimed, provisioned,
or reachable through the unified relay; that requires a separately approved live rollout/test.
