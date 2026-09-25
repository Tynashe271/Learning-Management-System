<?php

namespace Tests\Concerns;

use App\Services\VirusScanner;

/** A scripted stand-in for the clamd virus scanner, connected through a real socket pair. */
trait FakesClamd
{
    /** @var list<resource> */
    private array $clamdSockets = [];

    /** A connected pair: the scanner gets one end; the other already holds clamd's scripted answer. */
    private function pair(string $answer): array
    {
        $domain = DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX;
        [$client, $server] = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, $answer);
        $this->clamdSockets[] = $server;

        return [$client, $server];
    }

    private function useScanner(callable $connector, bool $enabled = true, bool $failOpen = false): void
    {
        config(['lms.virus_scan.enabled' => $enabled, 'lms.virus_scan.fail_open' => $failOpen]);
        $this->app->instance(VirusScanner::class, new VirusScanner($connector));
    }

    private function closeClamdSockets(): void
    {
        foreach ($this->clamdSockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
