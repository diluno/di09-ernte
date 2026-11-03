<?php

// Client-facing mails (resources/views/emails/**, app/Mail/*).
return [
    'greeting' => 'Guten Tag',
    'greeting_named' => 'Guten Tag :name',
    'regards' => 'Freundliche Grüsse',
    'subject_label' => 'Betreff',

    'invoice_subject' => 'Rechnung :number',
    'invoice_kind' => 'Rechnung',
    'invoice_intro' => 'Anbei senden wir Ihnen die Rechnung :number als PDF.',
    'invoice_amount' => 'Rechnungsbetrag',
    'due_on' => 'Fällig am',
    'thanks' => 'Vielen Dank.',

    'reminder_subject' => 'Zahlungserinnerung Rechnung :number',
    'reminder_kind' => 'Zahlungserinnerung',
    'reminder_intro' => 'Wir möchten Sie freundlich an die noch offene Rechnung :number erinnern.',
    'overdue_since' => 'Fällig seit',
    'reminder_disregard' => 'Falls die Zahlung bereits unterwegs ist, betrachten Sie diese Nachricht bitte als gegenstandslos.',

    'estimate_subject' => 'Offerte :number',
    'estimate_kind' => 'Offerte',
    'estimate_intro' => 'Anbei senden wir Ihnen unsere Offerte :number als PDF.',
    'estimate_amount' => 'Offertbetrag',
    'valid_until' => 'Gültig bis',
    'estimate_closing' => 'Wir freuen uns auf Ihre Rückmeldung.',
];
