<?php

declare(strict_types=1);


namespace libproxy;


use pmmp\thread\ThreadSafeArray;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\thread\Thread;
use pocketmine\utils\Utils;
use RuntimeException;
use Socket;
use function gc_enable;
use function ini_set;
use function socket_bind;
use function socket_create;
use function socket_last_error;
use function socket_listen;
use function socket_set_option;
use function socket_strerror;
use const AF_INET;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_SNDBUF;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

class ProxyThread extends Thread
{
    public ?string $autoloaderPath = null;
    /** @var AttachableThreadSafeLogger */
    private AttachableThreadSafeLogger $logger;
    /** @var bool */
    private bool $ready = false;

    /** @var ThreadSafeArray */
    private ThreadSafeArray $mainToThreadBuffer;
    /** @var ThreadSafeArray */
    private ThreadSafeArray $threadToMainBuffer;

    /** @var SleeperHandlerEntry */
    private SleeperHandlerEntry $sleeperEntry;
    /** @var Socket */
    private Socket $notifySocket;

    /** @var string */
    private string $serverIp;
    /** @var int */
    private int $serverPort;

    public function __construct(?string $autoloaderPath, string $serverIp, int $serverPort, AttachableThreadSafeLogger $logger, ThreadSafeArray $mainToThreadBuffer, ThreadSafeArray $threadToMainBuffer, SleeperHandlerEntry $sleeperEntry, Socket $notifySocket)
    {
        $this->autoloaderPath = $autoloaderPath;

        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->logger = $logger;
        $this->notifySocket = $notifySocket;

        $this->mainToThreadBuffer = $mainToThreadBuffer;
        $this->threadToMainBuffer = $threadToMainBuffer;

        $this->sleeperEntry = $sleeperEntry;

        $this->setClassLoaders([Server::getInstance()->getLoader()]);
    }

    public function shutdown(): void
    {
        $this->isKilled = true;
    }

    public function startAndWait(int $options): void
    {
        $this->start($options);
        $this->synchronized(function (): void {
            while (!$this->ready and $this->getCrashInfo() === null) {
                $this->wait();
            }
        });
    }

    protected function onRun(): void
    {
        gc_enable();
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        ini_set('memory_limit', '512M');

        if ($this->autoloaderPath !== null) {
            require $this->autoloaderPath;
        }

        $proxy = new ProxyServer(
            $this->logger,
            $this->createServerSocket(),
            $this->mainToThreadBuffer,
            $this->threadToMainBuffer,
            $this->sleeperEntry,
            $this->notifySocket,
        );

        $this->synchronized(function (): void {
            $this->ready = true;
            $this->notify();
        });

        while (!$this->isKilled) {
            $proxy->tickProcessor();
        }

        $proxy->waitShutdown();
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
        if (!socket_set_option($serverSocket, SOL_TCP, TCP_NODELAY, 1)) {
            throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($serverSocket)));
        }

        if (Utils::getOS() !== Utils::OS_MACOS) {
            if (!socket_set_option($serverSocket, SOL_SOCKET, SO_SNDBUF, 8 * 1024 * 1024) || !socket_set_option($serverSocket, SOL_SOCKET, SO_RCVBUF, 8 * 1024 * 1024)) {
                throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($serverSocket)));
            }
        }

        return $serverSocket;
    }
}
