<?php

declare(strict_types=1);

namespace App\Modules\Admin\Exceptions;

use App\Modules\Admin\Services\Impersonator;
use App\Modules\Admin\Support\ProtectsOperators;
use App\Modules\Core\Exceptions\DomainException;

/**
 * The Admin module's failure modes (Phase 19a).
 *
 * Extends {@see DomainException} like every other module's, so the central renderer in
 * `bootstrap/app.php` turns each one into a toast on a web visit and the shared envelope on a JSON
 * one (ADR 0013). Each cause is a named constructor, so the code and the status are set in one
 * place.
 *
 * The messages are written for an operator rather than a customer: they may name a team or a
 * resource, because whoever reads them is already allowed to see both. They still never carry a
 * person's details — an operator reading why something was refused does not need the row.
 */
final class AdminException extends DomainException
{
    protected string $errorCode = 'admin.invalid';

    /**
     * A write reached a descriptor that declares no editable fields.
     */
    public static function readOnlyResource(string $label): self
    {
        $e = new self($label.' is read-only in the admin panel.');
        $e->errorCode = 'admin.read_only_resource';
        $e->status = 403;

        return $e;
    }

    /**
     * A delete reached a descriptor that has not opted in. The default, deliberately.
     */
    public static function deleteNotPermitted(string $label): self
    {
        $e = new self(
            $label.' cannot be deleted from the admin panel. Deleting it behind the module that owns it '
            .'would skip the cascade and the audit entry the product relies on.'
        );
        $e->errorCode = 'admin.delete_not_permitted';
        $e->status = 403;

        return $e;
    }

    /**
     * An account operation aimed at another platform operator, by somebody who is not a super
     * admin. See {@see ProtectsOperators}.
     */
    public static function targetIsOperator(): self
    {
        $e = new self(
            'That account is a platform operator, so only a super admin can run account operations '
            .'on it. Clearing a colleague’s second factor or revoking their sessions is not a '
            .'support task.'
        );
        $e->errorCode = 'admin.target_is_operator';
        $e->status = 403;

        return $e;
    }

    public static function cannotImpersonateAdmin(): self
    {
        $e = new self(
            'That account is a platform operator. Impersonating another operator would put their powers '
            .'behind your session with their name on the audit trail.'
        );
        $e->errorCode = 'admin.cannot_impersonate_admin';
        $e->status = 403;

        return $e;
    }

    public static function cannotImpersonateSelf(): self
    {
        $e = new self('You are already signed in as that account.');
        $e->errorCode = 'admin.cannot_impersonate_self';

        return $e;
    }

    public static function notImpersonating(): self
    {
        $e = new self('This session is not impersonating anybody.');
        $e->errorCode = 'admin.not_impersonating';

        return $e;
    }

    /**
     * The hard rule: an impersonated session may not do anything that leaves the building or that
     * the customer cannot undo. See {@see Impersonator}.
     */
    public static function forbiddenWhileImpersonating(): self
    {
        $e = new self(
            'That action is blocked while impersonating. It would either leave the building or be '
            .'something the customer cannot undo — do it as yourself, or ask them to.'
        );
        $e->errorCode = 'admin.forbidden_while_impersonating';
        $e->status = 403;

        return $e;
    }

    /**
     * A read or write aimed at a setting `config/admin.php` does not declare (Phase 19b).
     *
     * A 500, not a 422: the key came from our own code or our own form, so an undeclared one is a
     * programming error rather than something an operator typed wrong. Treating it as user error
     * would send somebody to change a config file that was never the problem.
     */
    public static function unknownSetting(string $key): self
    {
        $e = new self('There is no platform setting called "'.$key.'". Declare it in config/admin.php first.');
        $e->errorCode = 'admin.unknown_setting';
        $e->status = 500;

        return $e;
    }

    public static function teamAlreadySuspended(): self
    {
        $e = new self('That team is already suspended.');
        $e->errorCode = 'admin.team_already_suspended';

        return $e;
    }

    public static function teamNotSuspended(): self
    {
        $e = new self('That team is not suspended.');
        $e->errorCode = 'admin.team_not_suspended';

        return $e;
    }
}
