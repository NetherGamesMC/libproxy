<?php

declare(strict_types=1);


namespace libproxy;


use pocketmine\event\Listener;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\raklib\RakLibInterface;

class ProxyListener implements Listener
{
    public function onNetworkInterfaceRegister(NetworkInterfaceRegisterEvent $event): void
    {
        if ($event->getInterface() instanceof RakLibInterface) {
            $event->cancel();
        }
    }
}