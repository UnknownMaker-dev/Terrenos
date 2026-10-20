<?php

declare(strict_types=1);

namespace Unknown\Terrenos\listener;

use pocketmine\block\Anvil;
use pocketmine\block\BaseCake;
use pocketmine\block\BaseFire;
use pocketmine\block\BaseSign;
use pocketmine\block\Beacon;
use pocketmine\block\BedBase;
use pocketmine\block\Bell;
use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\Cauldron;
use pocketmine\block\DaylightSensor;
use pocketmine\block\Door;
use pocketmine\block\DragonEgg;
use pocketmine\block\FenceGate;
use pocketmine\block\FillableCauldron;
use pocketmine\block\FlowerPot;
use pocketmine\block\ItemFrame;
use pocketmine\block\Jukebox;
use pocketmine\block\Lectern;
use pocketmine\block\Lever;
use pocketmine\block\Loom;
use pocketmine\block\Note;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\RedstoneRepeater;
use pocketmine\block\RespawnAnchor;
use pocketmine\block\tile\Container;
use pocketmine\block\Trapdoor;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\PressurePlateUpdateEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntityTrampleFarmlandEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\event\player\PlayerBucketFillEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Axe;
use pocketmine\item\Bucket;
use pocketmine\item\Fertilizer;
use pocketmine\item\FireCharge;
use pocketmine\item\FireworkRocket;
use pocketmine\item\FlintSteel;
use pocketmine\item\GlassBottle;
use pocketmine\item\Hoe;
use pocketmine\item\Item;
use pocketmine\item\LiquidBucket;
use pocketmine\item\Shears;
use pocketmine\item\Shovel;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use Unknown\Terrenos\model\Action;
use ReflectionMethod;
use Unknown\Terrenos\Terrenos;

/**
 * Proteção de blocos feita por jogadores: quebrar, colocar, baldes, cliques
 * (contêineres, portas, molduras, botões, ferramentas, fogo), placas e plantações.
 */
final class BlockProtectionListener implements Listener {

	/** @var array<string, bool> classe do item => tem uso próprio em blocos */
	private static array $blockEffect = [];

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
	public function onBreak(BlockBreakEvent $event) : void {
		if(!$this->check->allows($event->getPlayer(), Action::BREAK_BLOCK, $event->getBlock()->getPosition())){
			$event->cancel();
		}
	}

	/**
	 * Confere cada bloco da colocação (portas e camas ocupam dois).
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onPlace(BlockPlaceEvent $event) : void {
		foreach($event->getTransaction()->getBlocks() as [, , , $block]){
			if(!$this->check->allows($event->getPlayer(), Action::PLACE_BLOCK, $block->getPosition())){
				$event->cancel();
				return;
			}
		}
	}

	/**
	 * O líquido vai para o bloco ao lado do clicado, que é o que o evento informa.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onBucketEmpty(PlayerBucketEmptyEvent $event) : void {
		if(!$this->check->allows($event->getPlayer(), Action::USE_BUCKET, $event->getBlockClicked()->getPosition())){
			$event->cancel();
		}
	}

	/**
	 * O evento informa o bloco ao lado do líquido; o líquido removido fica na face oposta.
	 * As duas posições são conferidas.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onBucketFill(PlayerBucketFillEvent $event) : void {
		$reported = $event->getBlockClicked();
		$liquid = $reported->getSide(Facing::opposite($event->getBlockFace()));
		$player = $event->getPlayer();
		if(!$this->check->allows($player, Action::USE_BUCKET, $liquid->getPosition()) || !$this->check->allows($player, Action::USE_BUCKET, $reported->getPosition())){
			$event->cancel();
		}
	}

	/**
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onSignChange(SignChangeEvent $event) : void {
		if(!$this->check->allows($event->getPlayer(), Action::MODIFY_BLOCK, $event->getSign()->getPosition())){
			$event->cancel();
		}
	}

	/**
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onTrample(EntityTrampleFarmlandEvent $event) : void {
		$entity = $event->getEntity();
		$pos = $event->getBlock()->getPosition();
		if($entity instanceof Player){
			if(!$this->check->allows($entity, Action::TRAMPLE, $pos, false)){
				$event->cancel();
			}
			return;
		}
		// mobs nunca pisoteiam plantação dentro de terrenos
		if($pos->isValid() && $this->plugin->getRegistry()->at($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) !== null){
			$event->cancel();
		}
	}

	/**
	 * Placa de pressão dentro de terreno: não é acionada por quem não pode interagir
	 * (a não ser que alguém autorizado também esteja em cima dela). Mobs e itens seguem
	 * o comportamento normal, para não quebrar mecanismos do próprio dono.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onPressurePlate(PressurePlateUpdateEvent $event) : void {
		$pos = $event->getBlock()->getPosition();
		$denied = false;
		foreach($event->getActivatingEntities() as $entity){
			if(!$entity instanceof Player){
				continue;
			}
			if($this->check->allows($entity, Action::USE_BLOCK, $pos, false)){
				return;
			}
			$denied = true;
		}
		if($denied){
			$event->cancel();
		}
	}

	/**
	 * Clique direito: o uso do bloco (abrir, alternar, aplicar ferramenta) e o uso do
	 * item (acender fogo, colocar pintura/ovo) são negados separadamente, assim quem
	 * pode construir mas não abrir baús ainda consegue colocar blocos encostados nele.
	 * Clique esquerdo: tirar item de moldura, apagar fogo e tocar o ovo do dragão.
	 *
	 * @priority HIGH
	 * @handleCancelled false
	 */
	public function onInteract(PlayerInteractEvent $event) : void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();
		if(!$pos->isValid()){
			return;
		}

		if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
			$side = $block->getSide($event->getFace());
			$check = match(true){
				$block instanceof ItemFrame => [Action::USE_ITEM_FRAME, $pos],
				$block instanceof DragonEgg => [Action::USE_BLOCK, $pos],
				$side instanceof BaseFire => [Action::BREAK_BLOCK, $side->getPosition()],
				default => null
			};
			if($check !== null && !$this->check->allows($player, $check[0], $check[1])){
				$event->cancel();
			}
			return;
		}

		$item = $event->getItem();
		$notified = false;

		if($event->useBlock()){
			[$action, $explicit] = $this->blockUseAction($block, $item);
			if(!$this->check->allows($player, $action, $pos, $explicit)){
				$event->setUseBlock(false);
				$notified = $explicit;
			}
		}

		if($event->useItem()){
			$action = self::itemUseAction($item);
			if($action !== null){
				$target = $block->getSide($event->getFace())->getPosition();
				if(!$this->check->allows($player, $action, $pos, !$notified) || !$this->check->allows($player, $action, $target, !$notified)){
					$event->setUseItem(false);
				}
			}
		}

		if(!$event->useBlock() && !$event->useItem() && !$item->canBePlaced()){
			$event->cancel();
		}
	}

	/**
	 * Ação de usar o bloco clicado. O segundo valor diz se é uma interação conhecida
	 * (avisa o jogador) ou um bloco qualquer (nega em silêncio, para não poluir a tela
	 * de quem só está tentando colocar um bloco).
	 *
	 * @return array{Action, bool}
	 */
	private function blockUseAction(Block $block, Item $item) : array {
		$pos = $block->getPosition();
		if($pos->isValid() && $pos->getWorld()->getTile($pos) instanceof Container){
			return [Action::OPEN_CONTAINER, true];
		}
		return match(true){
			$block instanceof Jukebox, $block instanceof Lectern, $block instanceof FlowerPot,
			$block instanceof Cauldron, $block instanceof FillableCauldron => [Action::OPEN_CONTAINER, true],
			$block instanceof Door, $block instanceof Trapdoor, $block instanceof FenceGate => [Action::USE_DOOR, true],
			$block instanceof ItemFrame => [Action::USE_ITEM_FRAME, true],
			$block instanceof BaseSign => [Action::MODIFY_BLOCK, true],
			self::isBlockTool($item) => [Action::MODIFY_BLOCK, true],
			$block instanceof Button, $block instanceof Lever, $block instanceof RedstoneRepeater,
			$block instanceof RedstoneComparator, $block instanceof DaylightSensor, $block instanceof Note,
			$block instanceof BedBase, $block instanceof BaseCake, $block instanceof Anvil,
			$block instanceof RespawnAnchor, $block instanceof Bell, $block instanceof Beacon,
			$block instanceof Loom, $block instanceof DragonEgg => [Action::USE_BLOCK, true],
			default => [Action::USE_BLOCK, false]
		};
	}

	/** Itens que alteram o bloco clicado (enxada, pá, machado, tesoura, farinha de osso, fogo). */
	private static function isBlockTool(Item $item) : bool {
		return $item instanceof Hoe || $item instanceof Shovel || $item instanceof Axe || $item instanceof Shears
			|| $item instanceof Fertilizer || $item instanceof FlintSteel || $item instanceof FireCharge;
	}

	/**
	 * Ação do item usado no bloco (o efeito acontece no bloco ao lado do clicado).
	 * Fogo conta como modificar; qualquer outro item com efeito próprio ao ser usado
	 * num bloco (pintura, ovo de spawn, itens de outros plugins...) conta como construir.
	 * Baldes ficam com os eventos próprios; frascos e fogos de artifício são inofensivos.
	 */
	private static function itemUseAction(Item $item) : ?Action {
		if($item instanceof FlintSteel || $item instanceof FireCharge){
			return Action::MODIFY_BLOCK;
		}
		if($item instanceof Bucket || $item instanceof LiquidBucket || $item instanceof GlassBottle || $item instanceof FireworkRocket){
			return null;
		}
		return self::hasBlockEffect($item) ? Action::PLACE_BLOCK : null;
	}

	/** True se a classe do item implementa o próprio uso em blocos (resultado guardado por classe). */
	private static function hasBlockEffect(Item $item) : bool {
		$class = $item::class;
		return self::$blockEffect[$class] ??= (new ReflectionMethod($item, "onInteractBlock"))->getDeclaringClass()->getName() !== Item::class;
	}
}
