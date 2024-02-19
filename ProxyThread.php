<?php

declare(strict_types=1);


namespace libproxy;


use NetherGames\Quiche\SocketAddress;
use pmmp\thread\ThreadSafeArray;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\thread\Thread;
use Socket;

class ProxyThread extends Thread
{
    private bool $ready = false;

    public function __construct(
        private ?string                    $autoloaderPath,
        private string                     $serverIp,
        private int                        $serverPort,
        private AttachableThreadSafeLogger $logger,
        private ThreadSafeArray            $mainToThreadBuffer,
        private ThreadSafeArray            $threadToMainBuffer,
        private SleeperHandlerEntry        $sleeperEntry,
        private Socket                     $notifySocket
    )
    {
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
            new SocketAddress($this->serverIp, $this->serverPort),
            $this->mainToThreadBuffer,
            $this->threadToMainBuffer,
            $this->sleeperEntry,
            $this->notifySocket
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
}
