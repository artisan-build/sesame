<?php

namespace App\Sesame\Wal;

use RuntimeException;

/**
 * Append-only JSON-lines log in hourly segments. Ingest appends here before it
 * returns a MessageId; the data plane reads forward from a checkpoint that it
 * commits in the same transaction as the rows it writes.
 */
final class WriteAheadLog
{
    /** @var array<string, resource> open append handles, kept across Octane requests */
    private static array $handles = [];

    public function __construct(private readonly string $directory, private readonly bool $fsync = true) {}

    public static function fromConfig(): self
    {
        return new self((string) config('sesame.wal.path'), (bool) config('sesame.wal.fsync'));
    }

    /** @param array<string, mixed> $record */
    public function append(array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $segment = $this->directory.'/'.self::segmentName(time());
        $handle = self::$handles[$segment] ?? $this->open($segment);

        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the write-ahead log.');
        }
        try {
            if (fwrite($handle, $line) !== strlen($line) || ! fflush($handle) || ($this->fsync && ! fsync($handle))) {
                throw new RuntimeException('Could not append to the write-ahead log.');
            }
        } finally {
            flock($handle, LOCK_UN);
        }
    }

    /**
     * Read complete records after a checkpoint.
     *
     * @return array{records: list<array<string, mixed>>, checkpoint: array{segment: string, offset: int}}
     */
    public function readFrom(string $segment, int $offset, int $limit): array
    {
        $records = [];
        foreach ($this->segments() as $name) {
            if ($segment !== '' && strcmp($name, $segment) < 0) {
                continue;
            }
            if ($name !== $segment) {
                [$segment, $offset] = [$name, 0];
            }
            $path = $this->directory.'/'.$name;
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                break;
            }
            fseek($handle, $offset);
            while (count($records) < $limit && ($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    break; // a record still being written; read it next time
                }
                $records[] = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                $offset += strlen($line);
            }
            fclose($handle);
            // Only move past a segment once its hour has closed and it is fully read.
            if (count($records) >= $limit || ! $this->closed($name) || $offset < (int) filesize($path)) {
                break;
            }
        }

        return ['records' => $records, 'checkpoint' => ['segment' => $segment, 'offset' => $offset]];
    }

    /** Delete fully consumed segments older than the checkpoint segment. */
    public function purgeBefore(string $segment): void
    {
        foreach ($this->segments() as $name) {
            if (strcmp($name, $segment) < 0) {
                @unlink($this->directory.'/'.$name);
                if (isset(self::$handles[$this->directory.'/'.$name])) {
                    fclose(self::$handles[$this->directory.'/'.$name]);
                    unset(self::$handles[$this->directory.'/'.$name]);
                }
            }
        }
    }

    /** @return list<string> */
    public function segments(): array
    {
        $names = array_map('basename', glob($this->directory.'/*.wal') ?: []);
        sort($names);

        return $names;
    }

    public static function segmentName(int $timestamp): string
    {
        return gmdate('YmdH', $timestamp).'.wal';
    }

    private function closed(string $name): bool
    {
        // Allow late writers that computed the segment just before the hour turned.
        return strcmp($name, self::segmentName(time() - 10)) < 0;
    }

    /** @return resource */
    private function open(string $segment)
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Could not create the write-ahead log directory.');
        }
        $handle = fopen($segment, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Could not open the write-ahead log.');
        }
        // Drop handles for older segments.
        foreach (self::$handles as $path => $old) {
            if (dirname($path) === $this->directory && $path !== $segment) {
                fclose($old);
                unset(self::$handles[$path]);
            }
        }

        return self::$handles[$segment] = $handle;
    }
}
