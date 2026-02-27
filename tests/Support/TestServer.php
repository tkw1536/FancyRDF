<?php

declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use RuntimeException;

use function fclose;
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
use function time_nanosleep;
use function unlink;
use function var_export;

use const AF_INET;
use const PHP_BINARY;
use const SOCK_STREAM;
use const SOL_TCP;

/**
 * TestServer is a helper class to spin up a http server for testing.
 *
 * This relies on PHP_BINARY being available and pointing to the system's PHP binary.
 *
 * @internal
 */
final class TestServer
{
    private string $address;
    private int $port;

    /** @var resource|null */
    private $process      = null;
    private bool $stopped = false;
    private string $scriptPath;

    /**
     * Creates a new TestServer and waits for it have started up.
     *
     * Despite the class having a destructor, the caller SHOULD call the stop function to explicitly stop the server once it is no longer needed.
     *
     * @param array<string, string> $headers Header name => value
     *
     * @throws RuntimeException If the server fails to start within a reasonable amount of time.
     */
    public function __construct(int $code, array $headers, string $body)
    {
        // Pick a free port first, to avoid having to clean up temporary file
        // if we can't find one.
        [$this->address, $this->port] = $this->pickFreePort();

        // Find a temporary filename to put the script in.
        $tempDir = sys_get_temp_dir();
        if ($tempDir === '') {
            throw new RuntimeException('Could not get system temp directory.');
        }

        $scriptPath = tempnam($tempDir, 'phpunit_test_server_');
        if ($scriptPath === false) {
            throw new RuntimeException('Could not create temporary server script.');
        }

        $this->scriptPath = $scriptPath;

        // Generate and write out the script to the file.
        if (
            file_put_contents(
                $this->scriptPath,
                $this->makeScript($code, $headers, $body),
            ) === false
        ) {
            unlink($this->scriptPath);

            throw new RuntimeException('Could not write temporary server script.');
        }

        // Open the process
        $pipes   = null;
        $process = proc_open(
            [PHP_BINARY, '-n', '-S', $this->address . ':' . $this->port, $this->scriptPath],
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

        // We don't need the streams, so close them immediately.
        // Let the process write whatever it wants.
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Finally wait for the server to accept connections.
        $this->process = $process;
        $this->wait();
    }

    /** @param array<string, string> $headers */
    private static function makeScript(int $code, array $headers, string $body): string
    {
        $code    = var_export($code, true);
        $headers = var_export($headers, true);
        $body    = var_export($body, true);

        return <<<PHP
<?php

// This file was automatically generated for use inside a PHPUnit test.
// It should be deleted after the test has been run.
declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use function header;
use function http_response_code;
use function ini_set;

ini_set('default_charset', '');

http_response_code($code);

foreach ($headers as \$n => \$v) {
    header(\$n . ": " . \$v, true);
}

echo $body;

PHP;
    }

    public function getBaseUrl(): string
    {
        return 'http://' . $this->address . ':' . $this->port;
    }

    /**
     * Stops the server and cleans up.
     */
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

    private const int WAIT_UNTIL_READY_TIMEOUT_NS = 60_000_000; // 60s
    private const int SLEEP_INTERVAL_NS           = 500_000; // .5s

    private function wait(): void
    {
        $success = false;

        try {
            $deadline = hrtime(true) + self::WAIT_UNTIL_READY_TIMEOUT_NS;

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

                time_nanosleep(0, self::SLEEP_INTERVAL_NS);
            }

            throw new RuntimeException('Timed out waiting for PHP server to start on ' . $this->getBaseUrl() . '.');
        } finally {
            if (! $success) {
                $this->stop();
            }
        }
    }

    private const string LOOPBACK_ADDRESS = '127.0.0.1';

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

        if (! socket_bind($socket, self::LOOPBACK_ADDRESS, 0)) {
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
