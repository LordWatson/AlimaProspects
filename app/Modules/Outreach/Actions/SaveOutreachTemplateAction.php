<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Outreach\Data\OutreachTemplateData;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a team's message brief (Phase 11).
 *
 * The only subtlety is the default flag: "at most one default per channel" is enforced here rather
 * than by a partial unique index, which MySQL doesn't have. Nominating a new default clears the
 * previous one in the same transaction, so there is never a moment with two — or, worse, a
 * constraint violation that leaves the team unable to change their mind.
 *
 * Must be called inside the team's context.
 */
final class SaveOutreachTemplateAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        Team $team,
        User $member,
        OutreachTemplateData $data,
        ?OutreachTemplate $template = null,
    ): OutreachTemplate {
        return DB::transaction(function () use ($team, $member, $data, $template): OutreachTemplate {
            $attributes = [
                'name' => $data->name,
                'channel' => $data->channel,
                'tone' => $data->tone,
                'locale' => $data->locale,
                'subject' => $data->subject,
                'body' => $data->body,
                'is_default' => $data->isDefault,
            ];

            if ($template instanceof OutreachTemplate) {
                $template->forceFill($attributes)->save();
            } else {
                $template = OutreachTemplate::query()->create([
                    'team_id' => $team->getKey(),
                    'created_by' => $member->getKey(),
                    ...$attributes,
                ]);
            }

            if ($data->isDefault) {
                // Everything else on this channel stands down. Scoped to the team explicitly as
                // well as by the global scope — this is a write, and a write that could reach
                // another tenant's rows if the context were ever unset is not worth the brevity.
                OutreachTemplate::query()
                    ->where('team_id', $team->getKey())
                    ->where('channel', $data->channel->value)
                    ->whereKeyNot($template->getKey())
                    ->update(['is_default' => false]);
            }

            $this->audit->log('outreach.template_saved', $template, [
                'channel' => $data->channel->value,
                'tone' => $data->tone->value,
                'locale' => $data->locale->value,
                'is_default' => $data->isDefault,
            ], $member);

            return $template;
        });
    }
}
