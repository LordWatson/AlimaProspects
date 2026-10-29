<?php

declare(strict_types=1);

namespace App\Modules\Signals\Contracts;

use App\Modules\Signals\Data\NewsItem;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Testing\FakeNewsSearchClient;

/**
 * A news search backend for Signals. The real implementation ({@see SerperNewsClient})
 * calls Serper `/news`; tests bind {@see FakeNewsSearchClient}. Returns
 * normalised, clamped, de-duplicated items — never a provider's raw shape.
 */
interface NewsSearchClient
{
    /**
     * Run one query and return the normalised news items (portal/ignored domains dropped, duplicate
     * URLs collapsed within the response).
     *
     * @return list<NewsItem>
     */
    public function search(SignalQuery $query): array;
}
