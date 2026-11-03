<?php

// Client-facing mails (resources/views/emails/**, app/Mail/*).
return [
    'greeting' => 'Hello,',
    'greeting_named' => 'Hello :name,',
    'regards' => 'Kind regards',
    'subject_label' => 'Subject',

    'invoice_subject' => 'Invoice :number',
    'invoice_kind' => 'Invoice',
    'invoice_intro' => 'Please find attached invoice :number as a PDF.',
    'invoice_amount' => 'Amount due',
    'due_on' => 'Due on',
    'thanks' => 'Thank you.',

    'reminder_subject' => 'Payment reminder: invoice :number',
    'reminder_kind' => 'Payment reminder',
    'reminder_intro' => 'This is a friendly reminder that invoice :number is still outstanding.',
    'overdue_since' => 'Due since',
    'reminder_disregard' => 'If your payment is already on its way, please disregard this message.',

    'estimate_subject' => 'Quote :number',
    'estimate_kind' => 'Quote',
    'estimate_intro' => 'Please find attached our quote :number as a PDF.',
    'estimate_amount' => 'Quote amount',
    'valid_until' => 'Valid until',
    'estimate_closing' => 'We look forward to hearing from you.',
];
