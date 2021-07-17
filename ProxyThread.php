<?php

declare(strict_types=1);


namespace libproxy;


use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;
use RuntimeException;
use Socket;
use Threaded;
use ThreadedLogger;
use Throwable;
use function error_get_last;
use function gc_enable;
use function ini_set;
use function register_shutdown_function;
use function socket_bind;
use function socket_create;
use function socket_last_error;
use function socket_listen;
use function socket_set_option;
use function socket_strerror;
use const AF_INET;
use const PTHREADS_INHERIT_NONE;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_SNDBUF;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;

class ProxyThread extends Thread
{
    /** @var string|null */
    public ?string $crashInfo = null;
    /** @var ThreadedLogger */
    protected ThreadedLogger $logger;
    /** @var bool */
    protected bool $cleanShutdown = false;
    /** @var bool */
    protected bool $ready = false;


    /** @var Threaded */
    private Threaded $mainToThreadBuffer;
    /** @var Threaded */
    private Threaded $threadToMainBuffer;

    /** @var SleeperNotifier */
    private SleeperNotifier $notifier;
    /** @var Socket */
    private Socket $notifySocket;

    /** @var string */
    private string $serverIp;
    /** @var int */
    private int $serverPort;
    /** @var bool */
    private bool $asyncDecompress;

    public function __construct(string $serverIp, int $serverPort, ThreadedLogger $logger, Threaded $mainToThreadBuffer, Threaded $threadToMainBuffer, SleeperNotifier $notifier, Socket $notifySocket, bool $asyncDecompress)
    {
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->logger = $logger;
        $this->notifySocket = $notifySocket;
        $this->asyncDecompress = $asyncDecompress;

        $this->mainToThreadBuffer = $mainToThreadBuffer;
        $this->threadToMainBuffer = $threadToMainBuffer;

        $this->notifier = $notifier;
    }

    /**
     * @return void
     */
    public function shutdownHandler(): void
    {
        if ($this->cleanShutdown !== true) {
            $error = error_get_last();

            if ($error !== null) {
                $this->logger->emergency('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
                $this->setCrashInfo($error['message']);
            } else {
                $this->logger->emergency('Proxy shutdown unexpectedly');
            }
        }
    }

    private function setCrashInfo(string $info): void
    {
        $this->synchronized(function (string $info): void {
            $this->crashInfo = $info;
            $this->notify();
        }, $info);
    }

    public function getCrashInfo(): ?string
    {
        return $this->crashInfo;
    }

    public function startAndWait(int $options = PTHREADS_INHERIT_NONE): void
    {
        $this->start($options);
        $this->synchronized(function (): void {
            while (!$this->ready and $this->crashInfo === null) {
                $this->wait();
            }
            if ($this->crashInfo !== null) {
                throw new RuntimeException("Proxy failed to start: $this->crashInfo");
            }
        });
    }

    protected function onRun(): void
    {
        try {
            gc_enable();
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');

            register_shutdown_function([$this, 'shutdownHandler']);

            $this->synchronized(function (): void {
                $this->ready = true;
                $this->notify();
            });

            $proxy = new ProxyServer(
                $this->logger,
                $this->createServerSocket(),
                $this->mainToThreadBuffer,
                $this->threadToMainBuffer,
                $this->notifier,
                $this->notifySocket,
                $this->asyncDecompress
            );

            while (!$this->isKilled) {
                $proxy->tickProcessor();
            }

            $proxy->waitShutdown();
            $this->cleanShutdown = true;
        } catch (Throwable $e) {
            $this->setCrashInfo($e->getMessage());
            $this->logger->logException($e);
        }
    }

    private function createServerSocket(): Socket
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($serverSocket === false) {
            throw new RuntimeException("Failed to create socket: " . socket_strerror(socket_last_error()));
        }
        if (!socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($serverSocket)));
        }
        if (!socket_bind($serverSocket, $this->serverIp, $this->serverPort)) {
            throw new RuntimeException("Failed to bind to socket: " . socket_strerror(socket_last_error($serverSocket)));
        }
        if (!socket_listen($serverSocket, 10)) {
            throw new RuntimeException("Failed to listen to socket: " . socket_strerror(socket_last_error($serverSocket)));
        }
        if (!socket_set_option($serverSocket, SOL_SOCKET, SO_SNDBUF, 8 * 1024 * 1024) || !socket_set_option($serverSocket, SOL_SOCKET, SO_RCVBUF, 8 * 1024 * 1024)) {
            throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($serverSocket)));
        }

        return $serverSocket;
    }
}