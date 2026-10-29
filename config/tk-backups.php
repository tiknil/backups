<?php

use Spatie\DbDumper\Compressors\Bzip2Compressor;

return [

    'destination_disk' => env('BACKUP_DESTINATION_DISK', 'backups'),

    /*
     * Custom settings for the spatie/db-dumper config.
     */
    'dumper_settings' => [
        'mysql' => [
            'quick' => true,                // Reduces RAM usage
            'single-transaction' => true,   // Disable table locking during backup
            'skip-comments' => true,        // Skip comments in the resulting .sql file
            'gtid-purged' => 'OFF',         // Disables  binari log so import does not require SUPER privilege
            'extra_options' => [
                // '--skip-ssl',            // Should only be used for testing in local environments
                '--order-by-primary',       // Orders data by primary keys. Significantly improves import speed
            ],
        ],
    ],

    'retention_days' => [
        'daily' => 8,
        'weekly' => 35,
        'monthly' => 100,
        'yearly' => 400,
    ],

    // Number of parallel S3 requests
    'concurrency' => (int) env('BACKUP_CONCURRENCY', 8),
    // Number of rclone parallel transfers
    'transfer_concurrency' => (int) env('BACKUP_TRANSFER_CONCURRENCY', 16),
    // Cutoff for using multipart request during rclone copies
    // NB: use 5M for digitalocean spaces sources, otherwise there are checksum issue due to different calc strategies
    'multipart_cutoff' => env('BACKUP_MULTIPART_CUTOFF', '5M'),

    'file_sources' => [
        /*
         * Each entry is a source disk whose files will be incrementally
         * backed up to the destination disk (tk-backups.destination_disk).
         *
         * 'disk'    : the filesystems disk name. Both 's3' and 'local'
         *             disks are supported.
         * 'folder'  : optional sub-folder (string) or list of sub-folders
         *             within that disk to scope the backup. Each folder is
         *             backed up separately, to `{prefix}/{disk}/{folder}`.
         * 'exclude' : optional rclone pattern (string) or list of patterns
         *             to skip, relative to the root of each folder. Excluded
         *             files are also ignored by the deletion detection, so
         *             they never remove previously backed up files.
         *
         * e.g.
         * [ 'disk' => 's3', 'folder' => 'blog' ]
         * [ 'disk' => 's3', 'folder' => ['blog', 'docs'], 'exclude' => ['*.tmp', '/cache/**'] ]
         * [ 'disk' => 'local', 'folder' => 'uploads' ]
         */
    ],

    // La configurazione di spatie/laravel-backup usata per creare lo .zip giornaliero
    'laravel-backups' => [
        'backup' => [
            // Verrà ignorato e forzato a "daily"
            // 'name' => env('APP_NAME').'-backups',

            'source' => [

                'files' => [

                    'include' => [
                        // It's useless to include gitted files
                        storage_path('app'),
                        storage_path('logs'),
                    ],

                    'exclude' => [
                        base_path('vendor'),
                        base_path('node_modules'),
                    ],
                    'follow_links' => false,

                    'ignore_unreadable_directories' => false,

                    /*
                    * This path is used to make directories in resulting zip-file relative
                    * Set to `null` to include complete absolute path
                    * Example: base_path()
                    */
                    'relative_path' => base_path(),
                ],

                /*
                * The names of the connections to the databases that should be backed up
                * MySQL, PostgreSQL, SQLite and Mongo databases are supported.
                *
                * For a complete list of available customization options, see https://github.com/spatie/db-dumper
                */
                'databases' => [
                    'mysql',
                ],
            ],

            /*
            * The database dump can be compressed to decrease diskspace usage.
            *
            * Out of the box Laravel-backup supplies
            * Spatie\DbDumper\Compressors\GzipCompressor::class.
            *
            * You can also create custom compressor. More info on that here:
            * https://github.com/spatie/db-dumper#using-compression
            *
            * If you do not want any compressor at all, set it to null.
            */
            'database_dump_compressor' => Bzip2Compressor::class,

            /*
            * The file extension used for the database dump files.
            *
            * If not specified, the file extension will be .archive for MongoDB and .sql for all other databases
            * The file extension should be specified without a leading .
            */
            'database_dump_file_extension' => '',

            'destination' => [

                /*
                * The filename prefix used for the backup zip file.
                */
                'filename_prefix' => '',

                'disks' => [
                    // Da tenere in sync con il destination_disk indicato ad inizio file
                    env('BACKUP_DESTINATION_DISK', 'backups'),
                ],
            ],

            /*
            * The directory where the temporary files will be stored.
            */
            'temporary_directory' => storage_path('app/backup-temp'),

            'password' => env('BACKUP_ARCHIVE_PASSWORD'),

            /*
            * The encryption algorithm to be used for archive encryption.
            * You can set it to `null` or `false` to disable encryption.
            *
            * When set to 'default', we'll use ZipArchive::EM_AES_256 if it is
            * available on your system.
            */
            'encryption' => 'default',
        ],
    ],
];
