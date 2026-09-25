<?php

namespace App\Support;

use RuntimeException;

/**
 * A minimal writer and reader for .tar.gz files, so backups need nothing beyond PHP's built-in zlib (the zip and phar
 * extensions are not always installed). Entries are plain files with names up to 100 characters or, for longer names, the
 * standard prefix field; sizes up to 8 GiB.
 */
class TarGz
{
    /** Starts a new archive at $path. */
    public static function create(string $path): TarGzWriter
    {
        return new TarGzWriter($path);
    }

    /**
     * Calls $each(name, size, stream) for every file in the archive, in order. The stream is positioned at the start of the
     * file's content and is only valid during the call.
     */
    public static function read(string $path, callable $each): void
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }
        try {
            while (true) {
                $header = self::readExactly($handle, 512);
                if ($header === '' || trim($header, "\0") === '') {
                    break; // the end-of-archive blocks
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix.'/'.$name;
                }
                $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
                $stream = fopen('php://temp/maxmemory:4194304', 'w+b');
                $left = $size;
                while ($left > 0) {
                    $chunk = self::readExactly($handle, min(1048576, $left));
                    if ($chunk === '') {
                        throw new RuntimeException("The archive is cut short inside {$name}.");
                    }
                    fwrite($stream, $chunk);
                    $left -= strlen($chunk);
                }
                self::readExactly($handle, (512 - $size % 512) % 512); // padding
                rewind($stream);
                try {
                    $each($name, $size, $stream);
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            gzclose($handle);
        }
    }

    /** @param  resource  $handle */
    private static function readExactly($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $piece = gzread($handle, $length - strlen($data));
            if ($piece === false || $piece === '') {
                break;
            }
            $data .= $piece;
        }

        return $data;
    }
}

class TarGzWriter
{
    /** @var resource */
    private $handle;

    public function __construct(string $path)
    {
        $handle = gzopen($path, 'wb6');
        if ($handle === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }
        $this->handle = $handle;
    }

    /** Adds a small file from memory. Returns its SHA-256. */
    public function addString(string $name, string $contents): string
    {
        $this->header($name, strlen($contents));
        gzwrite($this->handle, $contents);
        $this->pad(strlen($contents));

        return hash('sha256', $contents);
    }

    /**
     * Adds a file read from a stream of known length. Returns its SHA-256, computed as it is copied.
     *
     * @param  resource  $stream
     */
    public function addStream(string $name, $stream, int $size): string
    {
        $this->header($name, $size);
        $hash = hash_init('sha256');
        $left = $size;
        while ($left > 0) {
            $chunk = fread($stream, min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException("{$name} ended before its expected {$size} bytes.");
            }
            hash_update($hash, $chunk);
            gzwrite($this->handle, $chunk);
            $left -= strlen($chunk);
        }
        $this->pad($size);

        return hash_final($hash);
    }

    public function close(): void
    {
        gzwrite($this->handle, str_repeat("\0", 1024));
        gzclose($this->handle);
    }

    private function header(string $name, int $size): void
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 155), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                throw new RuntimeException("The name {$name} is too long for the archive.");
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $block = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12', $name, '0000644', '0000000', '0000000', sprintf('%011o', $size), sprintf('%011o', time()), '        ', '0', '', 'ustar', '00', '', '', '', '', $prefix, '');
        $sum = 0;
        foreach (str_split($block) as $byte) {
            $sum += ord($byte);
        }
        $block = substr_replace($block, sprintf('%06o', $sum)."\0 ", 148, 8);
        gzwrite($this->handle, $block);
    }

    private function pad(int $size): void
    {
        $pad = (512 - $size % 512) % 512;
        if ($pad > 0) {
            gzwrite($this->handle, str_repeat("\0", $pad));
        }
    }
}
