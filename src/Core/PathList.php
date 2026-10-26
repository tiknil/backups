<?php

namespace Tiknil\Backups\Core;

/**
 * Classe di appoggio che si occupa di:
 *  - Salvare una lista di percorsi in un file temporaneo su disco
 *  - Permettere di iterare su questa lista
 *
 *  In questo modo la lista di file non viene tenuta in memoria: su storage molto grandi causa problemi di memoria.
 */
final class PathList implements \IteratorAggregate
{
    /** @var resource|null */
    private $stream;

    private int $count = 0;

    public function __construct()
    {
        $this->stream = tmpfile();

        if ($this->stream === false) {
            $this->stream = null;

            throw new \RuntimeException('Could not create a temporary path list.');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function append(string $path): void
    {
        $stream = $this->stream();
        $line = $path."\n";
        $length = strlen($line);
        $offset = 0;

        if (fseek($stream, 0, SEEK_END) !== 0) {
            throw new \RuntimeException('Could not seek in the temporary path list.');
        }

        while ($offset < $length) {
            $written = fwrite($stream, substr($line, $offset));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write the temporary path list.');
            }

            $offset += $written;
        }

        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return \Generator<int, string> */
    public function getIterator(): \Generator
    {
        $stream = $this->stream();

        if (!rewind($stream)) {
            throw new \RuntimeException('Could not rewind the temporary path list.');
        }

        while (($line = fgets($stream)) !== false) {
            yield rtrim($line, "\n");
        }

        if (!feof($stream)) {
            throw new \RuntimeException('Could not read the temporary path list.');
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
        return $this->stream ?? throw new \RuntimeException('The temporary path list is closed.');
    }
}
