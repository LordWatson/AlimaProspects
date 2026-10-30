<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a team's message brief (Phase 11).
 *
 * Messages drafted from it keep their text and lose the link (`outreach_template_id` is nulled on
 * delete, not cascaded): a sent message is a record of what was sent, and retiring the brief it
 * came from must not take it with it.
 *
 * Must be called inside the team's context.
 */
final class DeleteOutreachTemplateAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(OutreachTemplate $template, User $member): void
    {
        DB::transaction(function () use ($template, $member): void {
            // Logged before the delete, so the subject id is still resolvable.
            $this->audit->log('outreach.template_deleted', $template, [
                'channel' => $template->channel->value,
                'was_default' => $template->is_default,
            ], $member);

            $template->delete();
        });
    }
}
