<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Project;

class ProjectImporter
{
    private const AMOUNT_BUDGETS = ['project_cost', 'task_fees'];

    /** @var array<string,bool> */
    private array $usedCodes = [];

    /**
     * @param  array<int,array>   $harvestProjects
     * @param  array<int,Client>  $clientMap  harvest client id => ernte Client
     * @return array<int,Project> harvest project id => ernte Project
     */
    public function import(array $harvestProjects, array $clientMap): array
    {
        $map = [];

        foreach ($harvestProjects as $row) {
            $client = $clientMap[$row['client']['id'] ?? null] ?? null;
            if (! $client) {
                continue; // orphan project with no imported client — skip
            }

            $budgetBy = $row['budget_by'] ?? 'none';
            $budget = (float) ($row['budget'] ?? 0);
            $isAmount = in_array($budgetBy, self::AMOUNT_BUDGETS, true);

            $map[$row['id']] = Project::create([
                'client_id' => $client->id,
                'name' => $row['name'],
                'code' => $this->uniqueCode($row['code'] ?? '', $row['name']),
                'status' => ($row['is_active'] ?? true) ? 'active' : 'archived',
                'billable' => (bool) ($row['is_billable'] ?? false),
                'rate_rappen' => (int) round(((float) ($row['hourly_rate'] ?? 0)) * 100),
                'budget_hours' => $isAmount ? 0 : (int) round($budget),
                'budget_amount_rappen' => $isAmount ? (int) round($budget * 100) : 0,
                'started_on' => $row['starts_on'] ?? null,
                'deadline_on' => $row['ends_on'] ?? null,
                'glyph' => '▦',
            ]);
        }

        return $map;
    }

    private function uniqueCode(string $code, string $name): string
    {
        $base = $code !== ''
            ? substr($code, 0, 32)
            : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'PRJ', 0, 6));

        $candidate = $base;
        $n = 2;
        while (isset($this->usedCodes[$candidate])) {
            $candidate = $base . '-' . $n++;
        }

        $this->usedCodes[$candidate] = true;
        return $candidate;
    }
}
