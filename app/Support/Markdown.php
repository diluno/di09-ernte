<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class Markdown
{
    /**
     * Convert user-entered markdown (e.g. the invoice/estimate notes) to safe HTML.
     *
     * Single newlines are preserved as <br> so plain-textarea input keeps its line
     * breaks, and raw HTML in the input is escaped to prevent injection.
     */
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "<br>\n",
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
