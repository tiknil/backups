# Changelog

### v1.0.0

- Unico comando rclone per fare sia la copia che il report del diff tra storage e backup (prima c'era prima il check e poi il copy).
- Ottimizzato metodo rclone di verifica file cambiati.
- Aggiunte config opzionali di ottimizzazione rclone (--checkers, --max-buffer-size e --file-cutoff)
- `compare_method` opzionale per ogni voce di `file_sources` (`full`, `size`, `checksum`), risolto in `FileSource`: vuoto significa `checksum` per dischi s3 e `full` per locali

**Breaking**: necessario rclone >= 1.71

### v0.7.0

- Aggiunta la possibilità di indicare un array di prefissi di file da escludere dai backup di un disco
- Aggiunta la possibilità di indicare un array di prefissi di file da includere nei backup di un disco
- Fix gestione output di rclone check

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
