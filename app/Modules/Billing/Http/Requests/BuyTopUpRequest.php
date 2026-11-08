<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\TopUpOption;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\TopUpCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Buy 250 more email reveals" (PL4).
 *
 * Two fields, and the second one is the point: the client sends the **option's index**, and the
 * catalogue turns that into a Stripe price. A request carrying a price would be a request that
 * could name a cheaper one, and a request carrying a quantity would be one that could name a
 * larger.
 *
 * Authorisation is the controller's `billing.manage` gate, as everywhere else in this module — the
 * same pair who manage members and seats, because this spends money.
 */
final class BuyTopUpRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only the toppable ones are offered: the enum's own answer, so a metric that stops
            // being sold stops validating without anybody editing this file.
            'metric' => ['required', Rule::in(array_map(
                static fn (BillingMetric $metric): string => $metric->value,
                array_filter(BillingMetric::cases(), static fn (BillingMetric $metric): bool => $metric->isToppable()),
            ))],
            'option' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * The chosen option, or null when the index names nothing — which is a price that has been
     * withdrawn since the page rendered, not a malformed request, so it is the controller that
     * turns it into a 404 rather than this that turns it into a 422 about an integer.
     */
    public function option(TopUpCatalogue $catalogue): ?TopUpOption
    {
        $metric = BillingMetric::from((string) $this->input('metric'));

        return $catalogue->find($metric, (int) $this->input('option'));
    }
}
