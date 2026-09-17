<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class EstimateInputRules
{
    public static function create(mixed $clientId, string $rateField = 'rate_rappen'): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'project_id' => ['nullable', 'integer', self::projectForClient($clientId)],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            ...self::assumptionRules(),
            ...self::lineRules('required', $rateField),
            ...self::recipientRules(),
        ];
    }

    public static function update(mixed $clientId, string $rateField = 'rate_rappen'): array
    {
        return [
            'client_id' => 'sometimes|required|integer|exists:clients,id',
            'project_id' => ['sometimes', 'nullable', 'integer', self::projectForClient($clientId)],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            ...self::assumptionRules(),
            ...self::lineRules('sometimes', $rateField),
            ...self::recipientRules(),
        ];
    }

    private static function projectForClient(mixed $clientId): mixed
    {
        return Rule::exists('projects', 'id')->where(
            fn ($query) => $query->where('client_id', $clientId)
        );
    }

    /**
     * Line items arrive either flat (`lines`) or grouped (`sections.*.lines`).
     * On create one of the two is required; on update both are optional.
     * Every line needs a title or a description (or both).
     */
    private static function lineRules(string $presence, string $rateField): array
    {
        $rateType = $rateField === 'rate_rappen' ? 'integer' : 'numeric';
        $lines = $presence === 'required' ? 'required_without:sections' : 'sometimes';
        $sections = $presence === 'required' ? 'required_without:lines' : 'sometimes';

        return [
            'lines' => "{$lines}|array|min:1",
            ...self::itemRules('lines.*', $rateType, $rateField),

            'sections' => "{$sections}|array|min:1",
            'sections.*.label' => 'nullable|string|max:120',
            'sections.*.title' => 'nullable|string|max:255',
            'sections.*.lines' => 'required|array|min:1',
            ...self::itemRules('sections.*.lines.*', $rateType, $rateField),
        ];
    }

    private static function itemRules(string $prefix, string $rateType, string $rateField): array
    {
        return [
            "{$prefix}.title" => 'nullable|string|max:255',
            "{$prefix}.description" => "nullable|required_without:{$prefix}.title|string|max:1000",
            "{$prefix}.hours" => 'required|numeric|min:0',
            "{$prefix}.{$rateField}" => "required|{$rateType}|min:0",
        ];
    }

    private static function assumptionRules(): array
    {
        return [
            'assumptions' => 'sometimes|nullable|array|max:30',
            'assumptions.*' => 'nullable|string|max:500',
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
