<?php

declare(strict_types=1);

namespace Unknown\Terrenos\listener;

use pocketmine\block\BaseFire;
use pocketmine\block\Block;
use pocketmine\block\Liquid;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\block\BlockExplodeEvent;
use pocketmine\event\block\BlockFormEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\block\ChestPairEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\Listener;
use pocketmine\world\Position;
use Unknown\Terrenos\Terrenos;
use function array_values;
use function count;

/**
 * Proteção contra o que não tem jogador por trás: fogo, líquidos e explosões vindos
 * de fora, e baús duplos que atravessam a borda do terreno.
 */
final class WorldProtectionListener implements Listener {

	public function __construct(
		private Terrenos $plugin
	) {}

	/**
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onBurn(BlockBurnEvent $event) : void {
		$target = $event->getBlock()->getPosition();
		$source = $event->getCausingBlock()->getPosition();
		if(!$this->fireAllowed($source, $target)){
			$event->cancel();
		}
	}

	/**
	 * Fogo e líquidos se espalhando. Outros espalhamentos (grama, micélio) não mexem
	 * em nada que o dono construiu e ficam liberados.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onSpread(BlockSpreadEvent $event) : void {
		$target = $event->getBlock()->getPosition();
		$source = $event->getSource();
		$new = $event->getNewState();
		if($new instanceof BaseFire || $source instanceof BaseFire){
			if(!$this->fireAllowed($source->getPosition(), $target)){
				$event->cancel();
			}
		}elseif($new instanceof Liquid || $source instanceof Liquid){
			if(!$this->liquidAllowed($source->getPosition(), $target)){
				$event->cancel();
			}
		}
	}

	/**
	 * Pedra/obsidiana formada quando um líquido de fora encosta em outro de dentro.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onForm(BlockFormEvent $event) : void {
		$cause = $event->getCausingBlock();
		if($cause instanceof Liquid && !$this->liquidAllowed($cause->getPosition(), $event->getBlock()->getPosition())){
			$event->cancel();
		}
	}

	/**
	 * Explosões de blocos (cama/âncora fora do lugar certo, etc.): tira da lista os
	 * blocos protegidos em vez de cancelar a explosão inteira.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onBlockExplode(BlockExplodeEvent $event) : void {
		$source = $event->getPosition();
		$event->setAffectedBlocks($this->filterExplosion($source, $event->getAffectedBlocks()));
		$event->setIgnitions($this->filterExplosion($source, $event->getIgnitions()));
	}

	/**
	 * Explosões de entidades (TNT, creeper, cristal do End...).
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onEntityExplode(EntityExplodeEvent $event) : void {
		$source = $event->getPosition();
		$event->setBlockList($this->filterExplosion($source, $event->getBlockList()));
		$event->setIgnitions($this->filterExplosion($source, $event->getIgnitions()));
	}

	/**
	 * Um baú colocado encostado num baú de outro terreno (ou de fora) não forma baú
	 * duplo: senão quem está do lado de fora abriria o conteúdo de dentro.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onChestPair(ChestPairEvent $event) : void {
		$a = $event->getLeft()->getPosition();
		$b = $event->getRight()->getPosition();
		if(!$a->isValid() || !$b->isValid() || $a->getWorld() !== $b->getWorld()){
			return;
		}
		if(!$this->plugin->getGuard()->sameZone($a->getWorld()->getFolderName(), $a->getFloorX(), $a->getFloorY(), $a->getFloorZ(), $b->getFloorX(), $b->getFloorY(), $b->getFloorZ())){
			$event->cancel();
		}
	}

	/**
	 * @param Block[] $blocks
	 * @return list<Block>
	 */
	private function filterExplosion(Position $source, array $blocks) : array {
		if(count($blocks) === 0 || !$source->isValid()){
			return array_values($blocks);
		}
		$guard = $this->plugin->getGuard();
		$world = $source->getWorld()->getFolderName();
		$inside = $this->plugin->getSettings()->explosionsInside;
		$sx = $source->getFloorX();
		$sy = $source->getFloorY();
		$sz = $source->getFloorZ();
		$kept = [];
		foreach($blocks as $block){
			$pos = $block->getPosition();
			if($guard->allowsExplosion($world, $sx, $sy, $sz, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $inside)){
				$kept[] = $block;
			}
		}
		return $kept;
	}

	private function fireAllowed(Position $source, Position $target) : bool {
		if(!$target->isValid()){
			return true;
		}
		return $this->plugin->getGuard()->allowsFire(
			$target->getWorld()->getFolderName(),
			$source->getFloorX(), $source->getFloorY(), $source->getFloorZ(),
			$target->getFloorX(), $target->getFloorY(), $target->getFloorZ(),
			$this->plugin->getSettings()->fireInside
		);
	}

	private function liquidAllowed(Position $source, Position $target) : bool {
		if(!$target->isValid()){
			return true;
		}
		return $this->plugin->getGuard()->allowsLiquid(
			$target->getWorld()->getFolderName(),
			$source->getFloorX(), $source->getFloorY(), $source->getFloorZ(),
			$target->getFloorX(), $target->getFloorY(), $target->getFloorZ()
		);
	}
}
