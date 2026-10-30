<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Data\InvoiceData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One past invoice. The PDF link is Stripe's own signed, expiring URL — see {@see InvoiceData}.
 *
 * @mixin InvoiceData
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InvoiceData $invoice */
        $invoice = $this->resource;

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'date' => $invoice->date->toIso8601String(),
            'total' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'pdf_url' => $invoice->pdfUrl,
        ];
    }
}
