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

    private static function lineRules(string $presence, string $rateField): array
    {
        $rateType = $rateField === 'rate_rappen' ? 'integer' : 'numeric';

        return [
            'lines' => "{$presence}|array|min:1",
            'lines.*.description' => 'required_with:lines|string|max:1000',
            'lines.*.hours' => 'required_with:lines|numeric|min:0',
            "lines.*.{$rateField}" => "required_with:lines|{$rateType}|min:0",
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
