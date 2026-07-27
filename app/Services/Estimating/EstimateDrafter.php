<?php

namespace App\Services\Estimating;

use Anthropic\Client;
use App\Models\BusinessProfile;
use App\Models\Client as ClientModel;
use App\Models\Estimate;
use App\Models\Project;
use RuntimeException;

/**
 * Turns a prose brief into a set of proposed estimate lines using Claude.
 *
 * Produces a *proposal only* — nothing is persisted here. The user reviews and
 * edits the lines in the create form, and EstimateBuilder does the real write
 * (and recomputes all money server-side).
 */
class EstimateDrafter
{
    /** How many of this client's own past estimates to show. */
    private const CLIENT_HISTORY = 3;

    /** How many estimates from the rest of the book to show as general house style. */
    private const STUDIO_HISTORY = 6;

    private const MAX_LINES_PER_ESTIMATE = 12;


    /**
     * @return array{title:?string, notes:?string, lines:array<int, array{description:string, hours:float, rate:int}>}
     */
    public function draft(string $brief, ClientModel $client, ?Project $project = null): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $llm = new Client(apiKey: $apiKey);

        $message = $llm->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 8000,
            system: [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [[
                'role' => 'user',
                'content' => $this->userPrompt($brief, $client, $project),
            ]],
            outputConfig: ['format' => [
                'type' => 'json_schema',
                'schema' => $this->schema(),
            ]],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to draft this estimate.');
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                break;
            }
        }

        if (! is_array($json) || ! isset($json['lines']) || ! is_array($json['lines'])) {
            throw new RuntimeException('Claude returned an unexpected response.');
        }

        $defaultRate = $this->defaultRate($client, $project);

        $lines = [];
        foreach ($json['lines'] as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $lines[] = [
                'description' => $description,
                'hours' => max(0.0, round((float) ($line['hours'] ?? 0), 2)),
                'rate' => max(0, (int) round((float) ($line['rate'] ?? $defaultRate))),
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('Claude did not propose any line items.');
        }

        return [
            'title' => $this->nullableString($json['title'] ?? null),
            'notes' => $this->nullableString($json['notes'] ?? null),
            'lines' => $lines,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You draft estimates ("Offerten") for a small Swiss design and development studio.

        Given a prose brief describing a piece of client work, propose the line items
        for an estimate: a short description, an hours figure, and an hourly rate in
        whole Swiss francs for each.

        Rules:
        - Break the work into the phases the studio would actually bill separately —
          typically somewhere between 3 and 8 lines. Do not pad with filler lines.
        - Descriptions are in the same language as the brief, in the studio's own
          voice: a short noun phrase naming the deliverable, not a sentence.
        - Hours are realistic for the described scope and rounded to a quarter hour.
        - Use the client's or project's usual hourly rate for every line unless the
          brief explicitly calls for a different one.
        - You are shown past estimates: some for this client, some for other clients.
          They are the house style — match how they break a job into lines, how they
          word a line, how many hours a comparable piece of work gets, and what they
          charge. This matters more than any generic notion of a good estimate.
          Where the two disagree, this client's own estimates win. Estimates marked
          [accepted] are the ones that actually won the work — weight them highest.
        - Set `title` only when the brief suggests a project name worth printing at the
          top of the PDF; otherwise leave it null.
        - Set `notes` only for a genuine caveat about the estimate (assumptions,
          exclusions, validity). Leave it null when there is nothing to say.

        This is a first draft that a human will review and edit before it is sent.
        Deliver the estimate at the scope the brief describes — do not widen it with
        work that was not asked for.
        PROMPT;
    }

    private function userPrompt(string $brief, ClientModel $client, ?Project $project): string
    {
        $profile = BusinessProfile::current();
        $currency = $profile->default_currency ?? 'CHF';

        $parts = [
            "Studio: {$profile->name}",
            "Currency: {$currency}",
            "Client: {$client->name}",
        ];

        if ($project) {
            $rate = (int) round(($project->rate_rappen ?? 0) / 100);
            $parts[] = "Project: {$project->name}".($rate > 0 ? " (usual rate {$currency} {$rate}/h)" : '');
        }

        $forClient = $this->pastEstimates(clientId: $client->id, limit: self::CLIENT_HISTORY);
        if ($forClient->isNotEmpty()) {
            $parts[] = "Past estimates for {$client->name}:\n".$this->render($forClient);
        }

        // Broader house style — the studio's recent work for everyone else. This is
        // the only grounding a brand-new client gets, so it always goes in.
        $elsewhere = $this->pastEstimates(
            exceptClientId: $client->id,
            limit: self::STUDIO_HISTORY,
            excludeIds: $forClient->pluck('id')->all(),
        );
        if ($elsewhere->isNotEmpty()) {
            $parts[] = "Recent estimates for other clients:\n".$this->render($elsewhere, withClient: true);
        }

        $parts[] = "Brief:\n".$brief;

        return implode("\n\n", $parts);
    }

    /**
     * Past estimates to ground the draft in, newest first. Accepted ones lead —
     * those are the estimates that actually won work, so they're the truest
     * record of how the studio scopes and prices a job.
     *
     * @param  array<int, int>  $excludeIds
     * @return \Illuminate\Support\Collection<int, Estimate>
     */
    private function pastEstimates(?int $clientId = null, ?int $exceptClientId = null, int $limit = 3, array $excludeIds = []): \Illuminate\Support\Collection
    {
        return Estimate::query()
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($exceptClientId, fn ($q) => $q->where('client_id', '!=', $exceptClientId))
            ->when($excludeIds, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->whereHas('lines')
            ->with(['client:id,name', 'lines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderByRaw("CASE status WHEN 'accepted' THEN 0 WHEN 'sent' THEN 1 ELSE 2 END")
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Render estimates grouped one block per estimate. The grouping is the point:
     * how a job gets split into lines is as much the house style as the wording.
     *
     * @param  \Illuminate\Support\Collection<int, Estimate>  $estimates
     */
    private function render(\Illuminate\Support\Collection $estimates, bool $withClient = false): string
    {
        $blocks = [];

        foreach ($estimates as $estimate) {
            $heading = $estimate->title ?: $estimate->number;
            if ($withClient && $estimate->client) {
                $heading .= " — {$estimate->client->name}";
            }
            if ($estimate->status === 'accepted') {
                $heading .= ' [accepted]';
            }

            $lines = [];
            foreach ($estimate->lines->take(self::MAX_LINES_PER_ESTIMATE) as $line) {
                $lines[] = sprintf(
                    '  - %s — %sh @ %d',
                    $line->description,
                    rtrim(rtrim((string) $line->hours, '0'), '.'),
                    (int) round($line->rate_rappen / 100),
                );
            }

            $blocks[] = $heading."\n".implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /** Hourly rate in whole francs to fall back on when Claude omits one. */
    private function defaultRate(ClientModel $client, ?Project $project): int
    {
        if ($project && $project->rate_rappen) {
            return (int) round($project->rate_rappen / 100);
        }

        // This client's most recent rate, else the studio's most recent one.
        $lastRate = $this->pastEstimates(clientId: $client->id, limit: 1)->first()?->lines->first()?->rate_rappen
            ?? $this->pastEstimates(limit: 1)->first()?->lines->first()?->rate_rappen;

        return $lastRate ? (int) round($lastRate / 100) : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'hours' => ['type' => 'number'],
                            'rate' => ['type' => 'number'],
                        ],
                        'required' => ['description', 'hours', 'rate'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'notes', 'lines'],
            'additionalProperties' => false,
        ];
    }
}
