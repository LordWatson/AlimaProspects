# Auth & Users

> Status: **MVP** · Owner: Platform · Last updated: 2026-10-28

## Purpose

Identity and access: registration, login, email verification, password reset, two-factor auth, team creation/switching, team invitations, team-scoped roles, and Sanctum API tokens for the mobile app. Owns membership management and the role/permission wiring (spatie/laravel-permission, team-scoped). It does **not** own the tenancy primitives (`teams`, `team_user`, `BelongsToTeam`, `TeamContext` — those are Core) or billing seats (Billing reads seat counts from here).

## Code map

- **Backend:** `app/Modules/Auth/…`
    - **Actions:** `CreateTeamAction`, `CreatePersonalTeamAction` (registration), `UpdateTeamAction`, `SwitchTeamAction`, `InviteTeamMemberAction`, `AcceptInvitationAction`, `ResendInvitationAction`, `RevokeInvitationAction`, `UpdateTeamMemberRoleAction`, `RemoveTeamMemberAction`, `IssueApiTokenAction`, `RevokeApiTokenAction`.
    - **Support:** `TeamMembership` (dual-writes the `team_user` pivot role **and** the spatie team-scoped role — the single place membership changes), `InvitationUrl` (temporary signed accept link).
    - **Models:** `TeamInvitation`. (The `User` model lives in `app/Models`, now with spatie `HasRoles`; `Team` is Core.)
    - **Data (DTOs):** `CreateTeamData`, `InviteMemberData`.
    - **Policies:** `TeamPolicy` (`view`, `update`, `delete`, `manageMembers`).
    - **Exceptions:** `TeamMembershipException` (last-owner, not-a-member), `InvitationException` (expired, already-accepted, email-mismatch, already-member).
    - **Mail:** `TeamInvitationMail` (queued; markdown view `resources/views/emails/team-invitation.blade.php`).
    - **Http:** Web controllers (`TeamController`, `TeamSettingsController`, `TeamSwitchController`, `TeamInvitationController`, `TeamMemberController`, `InvitationAcceptanceController`, `Settings/ApiTokenController`); API V1 controllers (`TeamController`, `TeamSwitchController`, `UserController`, `ApiTokenController`); FormRequests + Resources (`UserResource`, `TeamResource`, `ApiTokenResource`).
    - **Fortify:** `app/Actions/Fortify/CreateNewUser` now creates the user **and** their personal team (Owner) in one transaction.
- **Routes:**
    - Web (`app/Modules/Auth/routes/web.php`): `team.settings`, `team.update`, `teams.store`, `teams.switch`, `team.invitations.{store,resend,destroy}`, `team.members.{update,destroy}`, `api-tokens.{index,store,destroy}`, `invitations.show` (signed), `invitations.accept`.
    - API (`app/Modules/Auth/routes/api.php`, under `/api/v1`, `throttle:api`): `auth.token.issue` (guest, `throttle:auth-token`), `user.show`, `teams.{index,store,current,switch}`, `auth.tokens.{index,destroy}`, `auth.token.current`.
- **Frontend:** `resources/js/pages/settings/Team.vue`, `resources/js/pages/settings/ApiTokens.vue`, `resources/js/pages/auth/AcceptInvitation.vue`, `resources/js/components/TeamSwitcher.vue` (sidebar). Current team + team list are shared on every request via `HandleInertiaRequests` (`auth.currentTeam`, `auth.teams`).
- **Tests:** `tests/Feature/Auth/*` (registration→team, team management, invitations, API tokens, membership sync) + the existing Fortify flow tests.
- **Config / env vars:** `config/permission.php` (`teams => true`, `models.team => Team`); `config/fortify.php` (2FA confirm + passkeys); rate limiters `api`, `auth-token`, `login`, `two-factor`, `passkeys` (FortifyServiceProvider).

## Data model

`team_invitations` is the only table this module adds; it reuses Core's `teams` / `team_user` and spatie's permission tables (now team-scoped).

- **team_invitations:** `id`, `team_id` (FK→teams, cascade), `invited_by` (FK→users, nullOnDelete), `email`, `role` (string, `TeamRole`), `token` (unique, 64), `accepted_at?`, `expires_at?`, timestamps. Unique `(team_id, email)`. Tenant-scoped via `BelongsToTeam`. Status is **derived** (pending / accepted / expired), not stored.
- **team_user.role** (Core table): the **authoritative** membership role.
- **spatie roles** are seeded as four **global** roles (`team_id = null`): `owner`, `admin`, `member`, `read-only`. A user's assignment row in `model_has_roles` carries the active `team_id`, so the same user can hold different roles on different teams.

## Key flows

- **Registration → personal team.** `CreateNewUser` validates, then in a transaction creates the user and calls `CreatePersonalTeamAction` → `CreateTeamAction` (creates the team, `TeamMembership::add` as Owner, sets `current_team_id`, audits `team.created`). Fortify then logs in + sends verification.
- **Switch team.** `SwitchTeamAction` guards membership via `User::switchTeam()`; non-members get 403 (web + API). Sets `TeamContext` + audits `team.switched`.
- **Invite → accept.** `InviteTeamMemberAction` upserts a `team_invitations` row (idempotent per team+email), audits, and **after commit** sends `TeamInvitationMail` with a temporary signed link (`InvitationUrl`). The invitee opens `invitations.show` (signed + auth), then POSTs `invitations.accept`; `AcceptInvitationAction` validates (pending, not expired, email matches, not already a member), `TeamMembership::add`s them with the invited role, marks `accepted_at`, audits `team.invitation_accepted`, and the controller switches them into the team.
- **Role change / removal.** `UpdateTeamMemberRoleAction` / `RemoveTeamMemberAction` refuse to touch the **last owner** (`TeamMembershipException::lastOwner`); both audit.
- **API tokens.** Mobile exchanges credentials at `POST /api/v1/auth/token` (`IssueApiTokenAction`; 2FA accounts are directed to the web screen until Phase 18). Tokens are also managed from **Settings → API tokens** (plaintext shown once). `RevokeApiTokenAction` deletes by id (owner-scoped). Both audit.
- **Spatie team context.** `LogContext` (Core) calls `setPermissionsTeamId($user->current_team_id)` early each request so team-scoped role checks resolve against the active team.

## External dependencies

None beyond mail transport. (The invitation mailable is queued and delivered via the configured mailer.)

## Permissions

`TeamPolicy` reads the authoritative `team_user` role via `User::roleOn()`:

| Ability                                                     | Owner | Admin | Member | Read-only |
| ----------------------------------------------------------- | :---: | :---: | :----: | :-------: |
| `view` (see team + members)                                 |  ✅   |  ✅   |   ✅   |    ✅     |
| `update` (team settings)                                    |  ✅   |  ✅   |   —    |     —     |
| `manageMembers` (invite/resend/revoke, role change, remove) |  ✅   |  ✅   |   —    |     —     |
| `delete` (whole team)                                       |  ✅   |   —   |   —    |     —     |

Invitations can grant Admin / Member / Read-only only (Owner is seeded, never invited). The last Owner can neither be demoted nor removed.

## Known limitations / TODO

- API token issuance for **2FA-enabled** accounts is deferred to the Mobile phase (18) — returns `423` with a pointer to the web screen.
- Team settings act on the **active team**; switch first, then manage (no per-team deep-link management UI yet).
- SSO/SAML, SCIM and passkey polish are post-MVP (ADR 0004).
- Dark-mode / brand tokens for the new screens arrive with Phase 2 (they currently use the starter-kit component styles).

## Changelog

| Date       | Phase | Change                                                                                                                                                                                                                                                                                                                                                                                                                                | Files                                                                                                                                                                                                                                                                                                                                                             |
| ---------- | ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-28 | 1     | Built the module: spatie teams mode + 4 seeded roles, registration→personal team (Owner), team create/switch/update, invitations (invite/accept/resend/revoke, signed mail), member role change/remove with last-owner guard, Sanctum token issue/revoke (web + API) with per-token abilities and `/api/v1` rate limiting, `TeamPolicy`, audit on all mutations, and the Team/API-token/Accept-invitation UI + sidebar team switcher. | `app/Modules/Auth/**`, `app/Actions/Fortify/CreateNewUser.php`, `app/Models/User.php`, `config/permission.php`, `database/migrations/*team_invitations*`, `database/seeders/{TeamRolesSeeder,DatabaseSeeder}.php`, `resources/js/{pages/settings/Team,pages/settings/ApiTokens,pages/auth/AcceptInvitation,components/TeamSwitcher}.vue`, `tests/Feature/Auth/**` |
