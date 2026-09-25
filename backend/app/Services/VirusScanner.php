<?php

namespace App\Services;

use Closure;

/** Streams a file to a ClamAV daemon using clamd's INSTREAM command. */
class VirusScanner
{
    private const CHUNK = 8192;

    /** @param  Closure|null  $connector  returns a connected stream; replaced in tests */
    public function __construct(private ?Closure $connector = null) {}

    public function enabled(): bool
    {
        return (bool) config('lms.virus_scan.enabled');
    }

    /**
     * @return string|null the signature name when infected, null when clean
     *
     * @throws ScannerUnavailable when the file cannot be scanned (daemon down, timeout, daemon error)
     */
    public function scan(string $path): ?string
    {
        $source = @fopen($path, 'rb');
        if ($source === false) {
            throw new ScannerUnavailable('The upload could not be read for scanning.');
        }
        $socket = null;
        try {
            $socket = $this->connect();
            $this->send($socket, "zINSTREAM\0");
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK);
                if ($chunk === false) {
                    throw new ScannerUnavailable('The upload could not be read for scanning.');
                }
                if ($chunk !== '') {
                    $this->send($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->send($socket, pack('N', 0));

            return $this->interpret($this->receive($socket));
        } finally {
            fclose($source);
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @return resource */
    private function connect()
    {
        if ($this->connector) {
            return ($this->connector)();
        }
        $config = config('lms.virus_scan');
        $socket = @stream_socket_client('tcp://'.$config['host'].':'.$config['port'], $code, $message, $config['timeout']);
        if ($socket === false) {
            throw new ScannerUnavailable("Cannot reach the virus scanner ({$message}).");
        }
        stream_set_timeout($socket, $config['timeout']);

        return $socket;
    }

    /** @param  resource  $socket */
    private function send($socket, string $data): void
    {
        for ($written = 0; $written < strlen($data);) {
            $n = @fwrite($socket, substr($data, $written));
            if ($n === false || $n === 0) {
                // clamd may have refused the stream (for example, it is larger than StreamMaxLength) and hung up.
                throw new ScannerUnavailable('The virus scanner closed the connection.');
            }
            $written += $n;
        }
    }

    /** @param  resource  $socket */
    private function receive($socket): string
    {
        $buffer = '';
        while (! str_contains($buffer, "\0") && ! feof($socket)) {
            $part = fread($socket, 1024);
            if (($part === false || $part === '') && (stream_get_meta_data($socket)['timed_out'] ?? false)) {
                throw new ScannerUnavailable('The virus scanner did not answer in time.');
            }
            $buffer .= (string) $part;
        }

        return trim($buffer, "\0 \r\n");
    }

    private function interpret(string $response): ?string
    {
        if ($response === 'stream: OK') {
            return null;
        }
        if (preg_match('/^stream: (.+) FOUND$/', $response, $match)) {
            return $match[1];
        }

        throw new ScannerUnavailable('The virus scanner returned an error: '.($response === '' ? 'no answer' : $response));
    }
}
