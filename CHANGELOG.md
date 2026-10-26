# Changelog

### v0.6.0

- Aggiunto comando `backup:hold-files` per applicare il legal hold a file sottoposti a backup, utile in caso di errore in fase di creazione del backup dei file.
- Aggiunta configurazione `multipart_cutoff` per la getione dell'upload multipart di file di grandi dimensioni

### v0.5.1

- Aggiunte opzioni --skip-db e --skip-files al comando di backup

### v0.5.0

- Report delle differenze storage su disco temporaneo, letti e scritti in streaming.
- `Rclone::check()` restituisce un `DiffReport` iterabile anziché un array.

### v0.4.1

- Fix per supporto a rclone < 1.60

### v0.4.0

- Implementato parallelismo richieste s3
- Fix: corretto supporto a spatie/laravel-backup 9.x

### v0.3.0

- Aggiunto supporto a personalizzazione opzioni mysqldump

### v0.2.0

- Aggiunto comando backup:restore
