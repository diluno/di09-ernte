<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Validation shared by the web forms and the MCP tools, so limits cannot drift.
 * The web sends `rate_rappen` (integer); MCP sends `rate` in francs.
 */
final class InvoiceInputRules
{
    public const ENTRY_MESSAGE = 'Some of the selected time entries cannot be billed on this invoice: each must belong to this client, be finished, billable and not yet invoiced.';

    public static function create(mixed $clientId, string $rateField = 'rate_rappen', string $linesPresence = 'required'): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'project_id' => ['nullable', 'integer', self::projectForClient($clientId)],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'entry_ids' => 'sometimes|array',
            'entry_ids.*' => ['integer', 'distinct', self::billableEntryForClient($clientId)],
            ...self::lineRules($linesPresence, $rateField),
            ...self::recipientRules(),
        ];
    }

    public static function update(string $rateField = 'rate_rappen'): array
    {
        return [
            'title' => 'sometimes|nullable|string|max:255',
            'locale' => 'sometimes|required|'.DocumentLocale::rule(),
            'notes' => 'sometimes|nullable|string|max:20000',
            'period_start' => 'sometimes|nullable|date',
            'period_end' => 'sometimes|nullable|date|after_or_equal:period_start',
            ...self::lineRules('sometimes', $rateField),
            ...self::recipientRules(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['entry_ids.*.exists' => self::ENTRY_MESSAGE];
    }

    private static function projectForClient(mixed $clientId): mixed
    {
        return Rule::exists('projects', 'id')->where(
            fn ($query) => $query->where('client_id', $clientId)
        );
    }

    /**
     * An entry may be linked only if it is one of this client's, finished,
     * billable and not on another invoice — the same set the "New invoice"
     * editor offers. Without this a crafted id could pull in someone else's time.
     */
    private static function billableEntryForClient(mixed $clientId): mixed
    {
        return Rule::exists('time_entries', 'id')->where(
            fn ($query) => $query
                ->whereIn('project_id', fn ($projects) => $projects->select('id')->from('projects')->where('client_id', $clientId))
                ->whereNotNull('ended_at')
                ->where('billable', true)
                ->whereNull('invoice_id')
        );
    }

    private static function lineRules(string $presence, string $rateField): array
    {
        $rateType = $rateField === 'rate_rappen' ? 'integer' : 'numeric';

        return [
            'lines' => "{$presence}|array|min:1",
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            "lines.*.{$rateField}" => "required|{$rateType}|min:0",
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ];
    }

    private static function recipientRules(): array
    {
        return [
            'recipients' => 'sometimes|array',
            'recipients.*.name' => 'required|string|max:255',
            'recipients.*.email' => 'required|email|max:255',
        ];
    }
}
