<?php

declare(strict_types=1);

namespace Unknown\Terrenos\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\scheduler\ClosureTask;
use Unknown\Terrenos\Terrenos;
use function time;

/**
 * Entrada e saída de jogadores (identidade, avisos de aluguel, limpeza de estado) e
 * carregamento de mundos (limpezas de contêineres pendentes).
 */
final class SessionListener implements Listener {

	public function __construct(
		private Terrenos $plugin
	) {}

	/**
	 * Atualiza o xuid/nome guardados do jogador nos terrenos e mostra os avisos de
	 * aluguel vencendo ou em carência (um pouco depois, para não se perder no login).
	 *
	 * @priority MONITOR
	 */
	public function onJoin(PlayerJoinEvent $event) : void {
		$player = $event->getPlayer();
		$changed = false;
		foreach($this->plugin->getRegistry()->all() as $plot){
			if($plot->refreshIdentity($player->getName(), $player->getXuid())){
				$changed = true;
			}
		}
		if($changed){
			$this->plugin->persist();
		}

		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void {
			if($player->isConnected()){
				$this->plugin->getWatcher()->remind($player, time());
			}
		}), 60);
	}

	/**
	 * @priority MONITOR
	 */
	public function onQuit(PlayerQuitEvent $event) : void {
		$this->plugin->getNotices()->forget($event->getPlayer());
	}

	/**
	 * @priority MONITOR
	 */
	public function onWorldLoad(WorldLoadEvent $event) : void {
		$this->plugin->getWiper()->resumeFor($event->getWorld());
	}
}
