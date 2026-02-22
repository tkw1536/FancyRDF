<?php

declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use RuntimeException;

use function file_exists;
use function file_put_contents;
use function hrtime;
use function is_int;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function socket_bind;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_getsockname;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;
use function var_export;

use const AF_INET;
use const PHP_BINARY;
use const SOCK_STREAM;
use const SOL_TCP;

/**
 * TestServer is a helper class to spin up a http server for testing.
 */
final class TestServer
{
    private string $address;
    private int $port;
    /** @var resource|null */
    private $process      = null;
    private bool $stopped = false;
    private string $scriptPath;

    /** @param array<string, string> $headers Header name => value (sent via header()). */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        [$this->address, $this->port] = $this->pickFreePort();

        $tempDir = sys_get_temp_dir();
        if ($tempDir === '') {
            throw new RuntimeException('Could not get system temp directory.');
        }

        $scriptPath = tempnam($tempDir, 'phpunit_test_server_');
        if ($scriptPath === false) {
            throw new RuntimeException('Could not create temporary server script.');
        }

        $this->scriptPath = $scriptPath;

        $headersExport = var_export($headers, true);
        $bodyExport    = var_export($body, true);
        $scriptContent = '<?php http_response_code(' . $statusCode . '); $h = ' . $headersExport . '; ini_set("default_charset", ""); foreach ($h as $n => $v) { header($n . ": " . $v, true); } echo ' . $bodyExport . ';';
        if (file_put_contents($this->scriptPath, $scriptContent) === false) {
            unlink($this->scriptPath);

            throw new RuntimeException('Could not write temporary server script.');
        }

        $process = proc_open(
            [PHP_BINARY, '-S', $this->address . ':' . $this->port, $this->scriptPath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if ($process === false) {
            unlink($this->scriptPath);

            throw new RuntimeException('Could not start PHP server process.');
        }

        $this->process = $process;

        $this->waitUntilReady();
    }

    public function getBaseUrl(): string
    {
        return 'http://' . $this->address . ':' . $this->port;
    }

    public function stop(): void
    {
        if ($this->stopped || $this->process === null) {
            return;
        }

        $this->stopped = true;
        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;

        if (! file_exists($this->scriptPath)) {
            return;
        }

        unlink($this->scriptPath);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function waitUntilReady(int $timeoutMs = 5000): void
    {
        $success = false;

        try {
            $deadline = hrtime(true) + $timeoutMs * 1_000_000;

            while (hrtime(true) < $deadline) {
                if ($this->process === null) {
                    throw new RuntimeException('PHP server process not started.');
                }

                $status = proc_get_status($this->process);
                if (! $status['running']) {
                    throw new RuntimeException('PHP server process died unexpectedly.');
                }

                $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if ($socket === false) {
                    continue;
                }

                $connected = false;
                try {
                    $connected = @socket_connect($socket, $this->address, $this->port);
                } finally {
                    socket_close($socket);
                }

                if ($connected) {
                    $success = true;

                    return;
                }

                usleep(10_000);
            }

            throw new RuntimeException('Timed out waiting for PHP server to start on port ' . $this->port . '.');
        } finally {
            if (! $success) {
                $this->stop();
            }
        }
    }

    /**
     * Picks a free address on the local machine to run the server on.
     *
     * @return array{string, int}
     *   A pair of address and port that was picked.
     */
    private function pickFreePort(): array
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new RuntimeException('Could not create socket to pick a free port.');
        }

        if (! socket_bind($socket, '127.0.0.1', 0)) {
            throw new RuntimeException('Could not bind socket to address.');
        }

        try {
            if (! socket_getsockname($socket, $addr, $port)) {
                throw new RuntimeException('Could not get socket name.');
            }

            if (! is_string($addr) || ! is_int($port)) {
                throw new RuntimeException('Could not get socket name.');
            }

            return [$addr, $port];
        } finally {
            socket_close($socket);
        }
    }
}
