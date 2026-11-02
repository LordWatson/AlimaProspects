<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Models\User;
use App\Modules\Admin\Data\ProvisionTeamData;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\SupportedLocale;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onboarding a business from the panel (Phase 19b).
 *
 * **There is no password field, and there never will be.** The invitation is the only way into a
 * provisioned account, so the one thing this form cannot accept is a credential an operator chose.
 *
 * The owner's email is deliberately **not** `unique:users`: an existing customer starting a second
 * business is a real case, and the dry run is what tells the operator that is what they are about
 * to do. Refusing it here would turn a legitimate scenario into a support ticket.
 */
final class ProvisionTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Refuse the fields this operator's role does not entitle them to set.
     *
     * **This closes a privilege escalation**, and it is the kind that only exists because one
     * screen does several modules' work. Provisioning is behind `teams.manage`, which Support
     * holds — but it calls `SetBillingModeAction`, `GrantPlanAction` and
     * `SetCapabilityOverrideAction`, whose own routes are behind `billing.manage` (Finance) and
     * `settings.manage` (super admin only). Without this, a Support operator could POST
     * `grant_plan=enterprise` with `capabilities_on[]=signals` and hand a tenant an unlimited plan
     * and feature overrides — abilities they are specifically not given, reached through a route
     * that does not mention them.
     *
     * It **refuses rather than strips**. Silently dropping the fields would provision a team that
     * differs from the form the operator just read back to a customer on the phone, which is a
     * worse outcome than an error message that names the ability they need.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var User|null $operator */
            $operator = $this->user();

            if (! $operator instanceof User) {
                return;
            }

            $wantsBilling = $this->filled(['grant_plan', 'grant_seats', 'grant_leads', 'grant_credits'])
                || ($this->input('billing_mode') !== null
                    && $this->input('billing_mode') !== BillingMode::Stripe->value);

            if ($wantsBilling && ! $operator->hasAdminAbility(AdminAbility::ManageBilling)) {
                $validator->errors()->add(
                    'billing_mode',
                    'Granting a plan or changing billing mode needs the '
                        .AdminAbility::ManageBilling->label().' ability. Provision on self-serve '
                        .'billing and ask finance to grant the plan.',
                );
            }

            if ($this->input('capabilities_on') !== null
                && $this->input('capabilities_on') !== []
                && ! $operator->hasAdminAbility(AdminAbility::ManageSettings)) {
                $validator->errors()->add(
                    'capabilities_on',
                    'Switching capabilities on needs the '.AdminAbility::ManageSettings->label()
                        .' ability. Provision without them and ask for the override separately.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team_name' => ['required', 'string', 'max:255'],
            'market' => ['required', Rule::enum(Market::class)],
            'timezone' => ['required', 'string', 'timezone'],
            'locale' => ['required', Rule::enum(SupportedLocale::class)],

            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],

            'billing_mode' => ['required', Rule::enum(BillingMode::class)],
            'grant_plan' => ['nullable', Rule::enum(PlanKey::class)],
            'grant_seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'grant_leads' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'grant_credits' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'grant_note' => ['nullable', 'string', 'max:500'],
            // Nullable with two meanings: absent = the configured default, 0 = no trial.
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'company_website' => ['nullable', 'url', 'max:2048'],
            'industry' => ['nullable', 'string', 'max:255'],
            'value_proposition' => ['nullable', 'string', 'max:2000'],
            'icp_name' => ['nullable', 'string', 'max:255'],

            'capabilities_on' => ['nullable', 'array'],
            'capabilities_on.*' => [Rule::enum(Capability::class)],
        ];
    }

    public function provision(): ProvisionTeamData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return new ProvisionTeamData(
            teamName: trim((string) $v['team_name']),
            market: Market::from((string) $v['market']),
            timezone: (string) $v['timezone'],
            locale: SupportedLocale::from((string) $v['locale']),
            ownerEmail: (string) $v['owner_email'],
            ownerName: trim((string) $v['owner_name']),
            billingMode: BillingMode::from((string) $v['billing_mode']),
            grantPlan: $this->nullableEnum($v['grant_plan'] ?? null),
            grantSeats: $this->nullableInt($v['grant_seats'] ?? null),
            grantLeads: $this->nullableInt($v['grant_leads'] ?? null),
            grantCredits: $this->nullableInt($v['grant_credits'] ?? null),
            grantNote: $this->nullableString($v['grant_note'] ?? null),
            trialDays: $this->nullableInt($v['trial_days'] ?? null),
            companyWebsite: $this->nullableString($v['company_website'] ?? null),
            industry: $this->nullableString($v['industry'] ?? null),
            valueProposition: $this->nullableString($v['value_proposition'] ?? null),
            icpName: $this->nullableString($v['icp_name'] ?? null),
            capabilitiesOn: $this->capabilities($v['capabilities_on'] ?? []),
        );
    }

    private function nullableEnum(mixed $value): ?PlanKey
    {
        return is_string($value) && $value !== '' ? PlanKey::tryFrom($value) : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function capabilities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => Capability::tryFrom($item) !== null,
        ));
    }
}
