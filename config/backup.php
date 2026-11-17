<?php

return [
    // Daily dumps land here (gitignored, outside public/).
    'path' => storage_path('backups/daily'),

    // How many daily files to keep.
    'keep' => (int) env('BACKUP_KEEP', 14),

    // Off-server copy: name of a filesystem disk (config/filesystems.php) to
    // copy each new backup to, e.g. an FTP/S3 disk. Empty = local only.
    'copy_to_disk' => env('BACKUP_COPY_DISK'),

    // Copies kept in Google Drive (folder "DB Backup") when Drive is connected.
    'drive_keep' => (int) env('BACKUP_DRIVE_KEEP', 30),

    // Database connection to dump (empty = the default connection).
    'connection' => env('BACKUP_CONNECTION'),

    // mysqldump binary (Hostinger has it on PATH).
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
];
