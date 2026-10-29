# Tiknil backups

Libreria per la gestione "The Tiknil way" dei backup per i progetti web.

## Installazione

```bash
composer require tiknil/backups
```

## Comandi

- `php artisan backup` — esegue il backup e la promozione a settimanale/mensile/annuale. Supporta `--skip-db` (salta backup spatie e promozione) e `--skip-files` (salta il backup incrementale dei file).
- `php artisan backup:checks` — verifica che l'ambiente sia pronto e tutto sia configurato correttamente
- `php artisan backup:hold-files` — riapplica il legal hold ai file già caricati sul bucket ma rimasti sbloccati (es. sincronizzazione iniziale interrotta): confronta ogni sorgente con il bucket via rclone e considera solo i file identici
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

Sotto la voce `file_sources`, aggiungi eventuali dischi di storage e sottocartelle da sottoporre a backup. `folder` accetta una stringa o una lista di cartelle (ognuna backuppata separatamente) e `exclude` una stringa o una lista di pattern rclone da saltare, relativi alla radice di ogni cartella:

```php
'file_sources' => [
    ['disk' => 's3', 'folder' => ['blog', 'docs'], 'exclude' => ['*.tmp', '/cache/**']],
    ['disk' => 'local', 'folder' => 'uploads'],
],
```

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

Le differenze dello storage vengono scritte e lette progressivamente in un report
temporaneo, senza accumulare la lista in RAM. Il report viene rimosso a fine
operazione, anche in caso di eccezioni; non supporta la ripresa.
La directory temporanea di PHP (`sys_get_temp_dir()`) deve avere spazio disponibile
ed essere su disco.

`backup:hold-files` usa lo stesso approccio: i file identici trovati da rclone
finiscono in un file temporaneo e i legal hold mancanti vengono applicati con un
numero limitato di richieste S3 concorrenti (`tk-backups.concurrency`).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
