# Changelog

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
