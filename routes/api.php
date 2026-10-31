<?php

declare(strict_types=1);

/*
 * The API surface is **entirely** `/api/v1`, and every route on it is declared by the module that
 * owns the domain — `app/Modules/<Module>/routes/api.php`, mounted under the versioned prefix with
 * the `api.v1.` name prefix by App\Modules\ModuleServiceProvider. That is what keeps a module's
 * HTTP surface next to its Actions and policies (ADR 0002), and what lets the route audit test
 * assert a property of *every* endpoint at once.
 *
 * This file stays empty on purpose. An unversioned route here would be outside the version policy
 * (see Core\Http\Middleware\ApiVersion), outside the shared error envelope and outside the token
 * scopes — Phase 17 removed the starter-kit `GET /api/user` closure for exactly that reason: it
 * returned the raw User model, which is the one thing the API rules forbid, and `GET /api/v1/user`
 * already did the job properly.
 */
