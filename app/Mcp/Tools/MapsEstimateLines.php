<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Shared line-item schema and franc→rappen mapping for the estimate write
 * tools. Validation already ran; this only normalises and rejects lines that
 * are blank after trimming.
 */
trait MapsEstimateLines
{
    private static function lineSchema(JsonSchema $schema): mixed
    {
        return $schema->object([
            'title' => $schema->string()->max(255)->description('Short task name, shown in bold.'),
            'description' => $schema->string()->max(1000)->description('What the task covers, shown under the title.'),
            'hours' => $schema->number()->required()->min(0),
            'rate' => $schema->number()->required()->min(0)->description('Hourly rate in francs.'),
        ]);
    }

    /** @throws \InvalidArgumentException */
    private static function toRappenLines(array $lines): array
    {
        return array_map(function (array $line) {
            $title = trim((string) ($line['title'] ?? ''));
            $description = trim((string) ($line['description'] ?? ''));
            if ($title === '' && $description === '') {
                throw new \InvalidArgumentException('Every line needs a title or a description.');
            }

            return [
                'title' => $title === '' ? null : $title,
                'description' => $description,
                'hours' => (float) $line['hours'],
                'rate_rappen' => (int) round(((float) $line['rate']) * 100),
            ];
        }, array_values($lines));
    }

    /** @throws \InvalidArgumentException */
    private static function toRappenSections(array $sections): array
    {
        return array_map(fn (array $section) => [
            'label' => $section['label'] ?? null,
            'title' => $section['title'] ?? null,
            'lines' => self::toRappenLines($section['lines'] ?? []),
        ], array_values($sections));
    }
}
