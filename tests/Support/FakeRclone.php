<?php

namespace Tests\Support;

/**
 * An executable that behaves like the rclone CLI for the few commands used by
 * the package: it records the arguments and the RCLONE_* environment, prints a
 * canned output and exits with the requested code.
 *
 * Writing the script at runtime keeps every test scenario independent and
 * avoids depending on a real rclone installation or on PATH.
 */
final class FakeRclone
{
    private const TEMPLATE = <<<'PHP'
        #!@PHP@
        <?php
        file_put_contents(
            @LOG@,
            json_encode([
                'argv' => array_slice($argv, 1),
                'env' => array_filter(
                    getenv(),
                    fn (string $key): bool => str_starts_with($key, 'RCLONE_'),
                    ARRAY_FILTER_USE_KEY,
                ),
            ])."\n",
            FILE_APPEND,
        );

        fwrite(STDOUT, @OUT@);
        fwrite(STDERR, @ERR@);
        exit(@EXIT@);
        PHP;

    /** @var array<int, string> */
    private static array $dirs = [];

    private function __construct(
        public readonly string $binary,
        private readonly string $log,
        private readonly string $dir,
    ) {}

    public static function create(string $stdout = '', string $stderr = '', int $exitCode = 0): self
    {
        // Crea un binario finto in un cartella temporanea
        // Il binario finto si occupa di registrare i comandi ricevuti nel file calls.jsonl ed esporre quanto ricevuto in $stdout
        // Così in fase di test si può invocare il binario fittizio senza fare danni, verificare che venga invocato con i giusti parametri,
        // e verificare che l'output preconfezionato venga parsato correttamente
        $dir = sys_get_temp_dir().'/tk-fake-rclone-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $binary = $dir.'/rclone';
        $log = $dir.'/calls.jsonl';

        file_put_contents($binary, strtr(self::TEMPLATE, [
            '@PHP@' => PHP_BINARY,
            '@LOG@' => var_export($log, true),
            '@OUT@' => var_export($stdout, true),
            '@ERR@' => var_export($stderr, true),
            '@EXIT@' => (string) $exitCode,
        ]));

        chmod($binary, 0755);

        self::$dirs[] = $dir;

        return new self($binary, $log, $dir);
    }

    /**
     * Every invocation, oldest first.
     *
     * @return array<int, array{argv: array<int, string>, env: array<string, string>}>
     */
    public function calls(): array
    {
        if (!is_file($this->log)) {
            return [];
        }

        return array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    /** @return array<int, string>|null */
    public function lastArgv(): ?array
    {
        return $this->calls()[0]['argv'] ?? null;
    }

    public static function cleanup(): void
    {
        foreach (self::$dirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($dir);
        }

        self::$dirs = [];
    }
}
