# Tiknil backups

Libreria per la gestione "The Tiknil way" dei backup per i progetti web.

## Installazione

```bash
composer require tiknil/backups
```

## Comandi

- `php artisan backup` — esegue il backup e la promozione a settimanale/mensile/annuale.
- `php artisan backup:checks` — verifica che l'ambiente sia pronto e tutto sia configurato correttamente
- `php artisan backup:restore {disk} {folder?}` — ripristina un disco (ed eventuale sottocartella) dal bucket di backup via rclone (inverso del backup files). Supporta `--dry-run`, `--force`, `--backup-disk=`, `--prefix=`

## Configurazione

Assicurati che `mysqldump` e `rclone` siano disponibili. Per installarli su Ubuntu:

```
sudo apt install -y mysql-client rclone
```

Configura il disco di backup in `config/filesystems.php`:

```
'backups' => [
    'driver' => 's3',
    'key' => env('BACKUP_ACCESS_KEY_ID'),
    'secret' => env('BACKUP_SECRET_ACCESS_KEY'),
    'region' => env('BACKUP_DEFAULT_REGION'),
    'bucket' => env('BACKUP_BUCKET'),
    'url' => env('BACKUP_URL'),
    'root' => env('BACKUP_FOLDER'),
    'endpoint' => env('BACKUP_ENDPOINT'),
    'use_path_style_endpoint' => env('BACKUP_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => true,
],
```

Imposta le variabili di ambiente con le credenziali del bucket di backup:

```
BACKUP_ACCESS_KEY_ID=
BACKUP_SECRET_ACCESS_KEY=
BACKUP_BUCKET=
BACKUP_DEFAULT_REGION=eu-south-1
BACKUP_ARCHIVE_PASSWORD=
```

Pubblica il file di configurazione della libreria:

```bash
php artisan vendor:publish --tag=tk-backups:config
```

Sotto la voce `file_sources`, aggiungi eventuali dischi di storage e sottocartelle da sottoporre a backup.

Schedula il backup quotidiano, in `routes/console.php`:

```
Schedule::command('backup')->dailyAt('02:00')->withoutOverlapping();
```

Verifica che tutto sia pronto con:

```
php artisan backup:check
```

## Funzionamento

Serve accesso ad un bucket s3 apposito di backup, configurato ad hoc con il versionamento attivo e delle regole di lifecycle per l'eliminazione dei file al termine del loro periodo di retention.

La libreria si occupa di:

- creare un backup tramite spatie/laravel-backup
- Se necessario, promuoverlo a settimanale / mensile / annuale
- Applicare i tag s3 per la retention dei dati
- Clonare i percorsi di storage configurati in una sottocartella `files/`

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
