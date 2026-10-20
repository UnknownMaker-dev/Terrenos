<?php

declare(strict_types=1);

namespace Unknown\Terrenos\service;

use pocketmine\block\tile\Container;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\world\World;
use Unknown\Terrenos\model\Plot;
use Unknown\Terrenos\Terrenos;
use function array_shift;
use function count;

/**
 * Esvazia os contêineres de um terreno liberado, alguns chunks por tick para não
 * travar o servidor. Se o mundo estiver descarregado, marca a limpeza como pendente
 * (gravada no arquivo) e a executa quando o mundo for carregado.
 */
final class ContainerWiper {

	private const CHUNKS_PER_TICK = 4;

	/** @var array<string, TaskHandler> id do terreno => tarefa em andamento */
	private array $running = [];

	public function __construct(
		private Terrenos $plugin
	) {}

	public function wipe(Plot $plot) : void {
		$this->cancel($plot->getId());
		$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->getWorld());
		if($world === null){
			$plot->setWipePending(true);
			$this->plugin->getLogger()->info("O mundo '{$plot->getWorld()}' está descarregado; os contêineres do terreno '{$plot->getId()}' serão esvaziados quando ele for carregado.");
			return;
		}
		$plot->setWipePending(true);
		$this->start($plot, $world);
	}

	/** Para a limpeza em andamento (ex.: terreno apagado). */
	public function cancel(string $id) : void {
		if(isset($this->running[$id])){
			$this->running[$id]->cancel();
			unset($this->running[$id]);
		}
	}

	/** Executa as limpezas pendentes dos mundos carregados (ao habilitar o plugin). */
	public function resumePending() : void {
		foreach($this->plugin->getServer()->getWorldManager()->getWorlds() as $world){
			$this->resumeFor($world);
		}
	}

	/** Executa as limpezas pendentes de um mundo que acabou de carregar. */
	public function resumeFor(World $world) : void {
		foreach($this->plugin->getRegistry()->all() as $plot){
			if($plot->isWipePending() && !$plot->hasOwner() && $plot->getWorld() === $world->getFolderName() && !isset($this->running[$plot->getId()])){
				$this->start($plot, $world);
			}
		}
	}

	private function start(Plot $plot, World $world) : void {
		$area = $plot->getArea();
		$chunks = [];
		for($cx = $area->getMinX() >> 4; $cx <= $area->getMaxX() >> 4; $cx++){
			for($cz = $area->getMinZ() >> 4; $cz <= $area->getMaxZ() >> 4; $cz++){
				$chunks[] = [$cx, $cz];
			}
		}
		$id = $plot->getId();
		$worldName = $world->getFolderName();
		$cleared = 0;

		$this->running[$id] = $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use ($id, $worldName, $area, &$chunks, &$cleared) : void {
			$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($worldName);
			$plot = $this->plugin->getRegistry()->get($id);
			if($world === null || $plot === null){
				// mundo descarregou no meio (fica pendente) ou o terreno foi apagado
				$this->cancel($id);
				return;
			}
			for($i = 0; $i < self::CHUNKS_PER_TICK && count($chunks) > 0; $i++){
				[$cx, $cz] = array_shift($chunks);
				$chunk = $world->loadChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getTiles() as $tile){
					$pos = $tile->getPosition();
					if($tile instanceof Container && !$tile->isClosed() && $area->containsBlock($worldName, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
						$tile->getRealInventory()->clearAll();
						$cleared++;
					}
				}
			}
			if(count($chunks) === 0){
				$plot->setWipePending(false);
				$this->plugin->persist();
				$this->plugin->getLogger()->info("Terreno '$id': $cleared contêiner(es) esvaziado(s).");
				$this->cancel($id);
			}
		}), 1);
	}
}
