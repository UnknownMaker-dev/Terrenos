<?php

declare(strict_types=1);

namespace Unknown\Terrenos\listener;

use pocketmine\entity\object\Painting;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use Unknown\Terrenos\model\Action;
use Unknown\Terrenos\Terrenos;
use function strtolower;

/**
 * PvP por bandeira e proteção de animais/entidades e pinturas contra
 * jogadores sem permissão. Flechas e outros projéteis contam como ataque de quem atirou.
 */
final class EntityProtectionListener implements Listener {

	private PlayerCheck $check;

	public function __construct(
		private Terrenos $plugin
	) {
		$this->check = new PlayerCheck($plugin);
	}

	/**
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onDamage(EntityDamageByEntityEvent $event) : void {
		$attacker = $event->getDamager();
		if(!$attacker instanceof Player){
			return;
		}
		$victim = $event->getEntity();
		$victimPos = $victim->getPosition();
		if(!$victimPos->isValid()){
			return;
		}

		if($victim instanceof Player){
			if($victim === $attacker){
				return;
			}
			$attackerPos = $attacker->getPosition();
			$world = $victimPos->getWorld()->getFolderName();
			$sameWorld = $attackerPos->isValid() && $attackerPos->getWorld() === $victimPos->getWorld();
			$allowed = $this->plugin->getGuard()->allowsPvp(
				$world,
				$sameWorld ? [$attackerPos->getFloorX(), $attackerPos->getFloorY(), $attackerPos->getFloorZ()] : [$victimPos->getFloorX(), $victimPos->getFloorY(), $victimPos->getFloorZ()],
				[$victimPos->getFloorX(), $victimPos->getFloorY(), $victimPos->getFloorZ()]
			);
			if(!$allowed){
				$event->cancel();
				$this->plugin->getNotices()->denied($attacker, "§cPvP desativado neste terreno.", $this->plugin->getSettings()->noticeCooldown);
			}
			return;
		}

		if(isset($this->plugin->getSettings()->unprotectedEntities[strtolower($victim::getNetworkTypeId())])){
			return;
		}
		// pintura é decoração: quebrá-la segue a bandeira de quebrar blocos
		$action = $victim instanceof Painting ? Action::BREAK_BLOCK : Action::HURT_ENTITY;
		if(!$this->check->allows($attacker, $action, $victimPos)){
			$event->cancel();
		}
	}
}
