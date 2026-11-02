{{--
    The AI usage Pulse card (Phase 20, ADR 0017). See App\Modules\AiPlatform\Observability\
    AiUsageCard for what the numbers are and — more importantly — what they are not: this is a
    live, sampled, seven-day rolling window, not the ledger anybody bills from.

    Pulse's own components and its own grey palette on purpose. The Alima brand governs the
    product's screens (CLAUDE.md §2a); this is a vendor operator dashboard, and a card painted in
    Cloud Cream in the middle of eight that are not would look like a bug.
--}}
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="AI usage"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="live, per prompt, past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
        <x-slot:actions>
            @php
                $message = "Live AI spend per prompt, from Pulse's rolling window.\n\n"
                    ."This is not the billing figure: it is sampled, trimmed after 7 days and\n"
                    ."disappears when Pulse is disabled. The authoritative per-team cost is the\n"
                    ."ai_requests ledger, in the admin panel's platform reporting.";
            @endphp
            <button title="{{ $message }}" @click="alert(@js($message))">
                <x-pulse::icons.information-circle class="w-5 h-5 stroke-gray-400 dark:stroke-gray-600" />
            </button>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($prompts->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Prompt</x-pulse::th>
                        <x-pulse::th class="text-right">Calls</x-pulse::th>
                        <x-pulse::th class="text-right">Cached</x-pulse::th>
                        <x-pulse::th class="text-right">Failed</x-pulse::th>
                        <x-pulse::th class="text-right">Spend</x-pulse::th>
                        <x-pulse::th class="text-right">Avg</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($prompts->take(100) as $prompt)
                        <tr wire:key="{{ $prompt->key }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $prompt->key }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $prompt->key }}">
                                    {{ $prompt->key }}
                                </code>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($prompt->calls) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ number_format($prompt->cached) }}
                            </x-pulse::td>
                            {{-- The one number worth colouring: a failing prompt is the reason
                                 somebody opened this screen. --}}
                            <x-pulse::td numeric @class([
                                'font-bold',
                                {{-- `red-600` rather than `red-500`: on Pulse's white card the
                                     lighter one is about 3.8:1, which is below WCAG AA for body
                                     text and only passes if you count bold at this size as large
                                     text, which it is not. Pulse's own cards use the 600 weight
                                     for the same reason. The colour is a second signal anyway —
                                     the column is headed "Failed" and the number is the value — so
                                     nothing here is conveyed by colour alone. --}}
                                'text-red-600 dark:text-red-400' => $prompt->failed > 0,
                                'text-gray-700 dark:text-gray-300' => $prompt->failed === 0,
                            ])>
                                {{ number_format($prompt->failed) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{-- Micro-units are the stored unit everywhere in this app
                                     (CLAUDE.md §2): displayed as major units to four places,
                                     because a day's spend on one prompt is often fractions of a
                                     cent and rounding it to two would show every row as 0.00. --}}
                                <strong>{{ number_format($prompt->costMicros / 1_000_000, 4) }}</strong>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $currency?->value ?? '' }}</span>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                <strong>{{ number_format($prompt->latencyMs) ?: '<1' }}</strong> ms
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            @if ($prompts->count() > 100)
                <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
            @endif
        @endif
    </x-pulse::scroll>
</x-pulse::card>
