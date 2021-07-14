<?php

declare(strict_types=1);


namespace libproxy;


use pocketmine\plugin\PluginBase;

class Proxy
{
    public function __construct(PluginBase $plugin)
    {
        $server = $plugin->getServer();
        $server->getPluginManager()->registerEvents(new ProxyListener(), $plugin);
        $server->getNetwork()->registerInterface(new ProxyNetworkInterface($server));
    }
}