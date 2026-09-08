<?php

return [
    'mirror_disk' => env('BACKUP_MIRROR_DISK') ?: null,
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
];
