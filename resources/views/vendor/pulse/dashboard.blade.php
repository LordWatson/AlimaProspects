{{--
    The Pulse dashboard (Phase 20, ADR 0017).

    Ordered by the question an operator arrives with, not by what Pulse happens to ship: the three
    cards mvp.md Phase 20 asks for - queues, slow queries and AI usage - are at the top, where
    somebody who has just been paged will see them without scrolling. Exceptions sit beside the
    queue, because a spike in one is usually the other.

    What this screen is **not** is the admin panel. `/admin/operations` (Phase 19c) answers the
    questions that need a tenant: which team's job failed, what a customer cost us, who to call.
    This answers the ones that need a clock - what is happening in the last hour, across
    everything, at a per-minute resolution the nightly rollup cannot see. Both are behind the same
    door (`config/pulse.php` - the admin middleware stack plus the `viewPulse` gate).
--}}
<x-pulse>
    <livewire:pulse.queues cols="6" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-queries cols="12" />

    {{-- Ours: live AI spend, failures and latency per prompt. Not the billing figure - see
         App\Modules\AiPlatform\Observability\AiUsageCard. --}}
    <livewire:ai-usage cols="6" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />

    <livewire:pulse.usage cols="4" rows="2" />

    <livewire:pulse.cache cols="4" />

    <livewire:pulse.servers cols="full" />
</x-pulse>
