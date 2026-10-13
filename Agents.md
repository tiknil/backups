Istruzioni per agenti AI che operano in questo repository.

## Progetto

La libreria si occupa di creare dei backup giornalieri / settimanali / mensili su un bucket S3, appositamente predisposto con la configurazione di Object Lock e Data retention necessari.

Si usa spatie/laravel-backup per il backup del DB e rclone per lo spostamento dei file di storage.

Lo scopo è avere una libreria unica che si occupa del backup, installata in tutti i nostro progetti web per uniformare l'esecuzone dei backup
