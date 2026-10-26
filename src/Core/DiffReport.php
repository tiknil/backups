<?php

namespace Tiknil\Backups\Core;

/**
 * Classe di appoggio che si occupa di:
 *  - Salvare la lista di file nuovi/modificati/eliminati in un file temporaneo su disco
 *  - Esporre metadati aggregati a riguardo (es. il conteggio totale)
 *  - Permettere di iterare su questa lista di file.
 *
 *  In questo modo la lista di file non viene tenuta in memoria: su storage molto grandi causa problemi di memoria.
 */
final class DiffReport implements \IteratorAggregate
{
    /** @var resource|null */
    private $stream;

    private int $changed = 0;

    private int $deleted = 0;

    public function __construct()
    {
        $this->stream = tmpfile();

        if ($this->stream === false) {
            $this->stream = null;

            throw new \RuntimeException('Could not create a temporary diff report.');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param array{status: string, path: string} $change */
    public function append(array $change): void
    {
        $stream = $this->stream();
        $line = json_encode($change, JSON_THROW_ON_ERROR)."\n";
        $length = strlen($line);
        $offset = 0;

        if (fseek($stream, 0, SEEK_END) !== 0) {
            throw new \RuntimeException('Could not seek in the temporary diff report.');
        }

        while ($offset < $length) {
            $written = fwrite($stream, substr($line, $offset));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write the temporary diff report.');
            }

            $offset += $written;
        }

        if (in_array($change['status'], ['+', '*'], true)) {
            $this->changed++;
        } elseif ($change['status'] === '-') {
            $this->deleted++;
        }
    }

    public function countChanged(): int
    {
        return $this->changed;
    }

    public function countDeleted(): int
    {
        return $this->deleted;
    }

    /** @return \Generator<int, string> */
    public function changed(): \Generator
    {
        foreach ($this as $change) {
            if (in_array($change['status'], ['+', '*'], true)) {
                yield $change['path'];
            }
        }
    }

    /** @return \Generator<int, string> */
    public function deleted(): \Generator
    {
        foreach ($this as $change) {
            if ($change['status'] === '-') {
                yield $change['path'];
            }
        }
    }

    /** @return \Generator<int, array{status: string, path: string}> */
    public function getIterator(): \Generator
    {
        $stream = $this->stream();

        if (!rewind($stream)) {
            throw new \RuntimeException('Could not rewind the temporary diff report.');
        }

        while (($line = fgets($stream)) !== false) {
            yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        if (!feof($stream)) {
            throw new \RuntimeException('Could not read the temporary diff report.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /** @return resource */
    private function stream()
    {
        return $this->stream ?? throw new \RuntimeException('The temporary diff report is closed.');
    }
}
