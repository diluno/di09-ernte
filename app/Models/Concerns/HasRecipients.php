<?php

namespace App\Models\Concerns;

/** For documents with a `recipients` snapshot ([{name,email}]) and a client. */
trait HasRecipients
{
    /**
     * Who the document is addressed to: its own recipients, or the client's
     * default contacts for documents created before recipients were stored.
     * The first entry is the "to" address; the rest are copied.
     *
     * @return array<int, array{name: ?string, email: string}>
     */
    public function effectiveRecipients(): array
    {
        return $this->recipients ?: ($this->client?->defaultRecipients() ?? []);
    }

    /** Name used in the mail greeting — the person the mail is actually sent to. */
    public function primaryRecipientName(): ?string
    {
        $name = trim((string) ($this->effectiveRecipients()[0]['name'] ?? ''));

        return $name !== '' ? $name : null;
    }
}
