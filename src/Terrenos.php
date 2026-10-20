<?php

declare(strict_types=1);

namespace Unknown\Terrenos;

use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use Unknown\Forja\config\SafeConfig;
use Unknown\Forja\economy\EconomyBridge;
use Unknown\Terrenos\command\PlotCommand;
use Unknown\Terrenos\listener\BlockProtectionListener;
use Unknown\Terrenos\listener\EntityProtectionListener;
use Unknown\Terrenos\listener\SessionListener;
use Unknown\Terrenos\listener\WorldProtectionListener;
use Unknown\Terrenos\model\Actor;
use Unknown\Terrenos\model\Guard;
use Unknown\Terrenos\model\PlotRegistry;
use Unknown\Terrenos\service\ContainerWiper;
use Unknown\Terrenos\service\LeaseWatcher;
use Unknown\Terrenos\service\Notices;
use Unknown\Terrenos\service\PlotService;
use Unknown\Terrenos\service\PlotStorage;
use Unknown\Terrenos\ui\PlotMenus;

final class Terrenos extends PluginBase {

	public const PERMISSION_USE = "terrenos.use";
	public const PERMISSION_ADMIN = "terrenos.admin";
	public const PERMISSION_BYPASS = "terrenos.bypass";

	private Settings $settings;
	private EconomyBridge $economy;
	private PlotRegistry $registry;
	private Guard $guard;
	private PlotStorage $storage;
	private PlotService $service;
	private ContainerWiper $wiper;
	private LeaseWatcher $watcher;
	private Notices $notices;
	private PlotMenus $menus;
	private ?TaskHandler $watchTask = null;

	protected function onEnable() : void {
		$this->registry = new PlotRegistry();
		$this->storage = new PlotStorage($this->getDataFolder() . "dados.json", $this->getLogger());
		$this->notices = new Notices();
		$this->loadSettings();

		$loaded = $this->storage->load($this->registry);
		$this->getLogger()->info("$loaded terreno(s) carregado(s).");

		$this->wiper = new ContainerWiper($this);
		$this->service = new PlotService($this);
		$this->watcher = new LeaseWatcher($this);
		$this->menus = new PlotMenus($this);

		$manager = $this->getServer()->getPluginManager();
		$manager->registerEvents(new BlockProtectionListener($this), $this);
		$manager->registerEvents(new EntityProtectionListener($this), $this);
		$manager->registerEvents(new WorldProtectionListener($this), $this);
		$manager->registerEvents(new SessionListener($this), $this);

		$this->getServer()->getCommandMap()->register($this->getName(), new PlotCommand($this));

		$this->startWatcher();
		$this->wiper->resumePending();
	}

	protected function onDisable() : void {
		$this->watchTask?->cancel();
		$this->watchTask = null;
		if(isset($this->storage, $this->registry)){
			$this->storage->save($this->registry);
		}
	}

	/**
	 * Relê o config.yml (preços padrão, durações, bandeiras, proteção e economia).
	 * Os terrenos em si não são recarregados do disco.
	 */
	public function reloadSettings() : void {
		$this->loadSettings();
		$this->startWatcher();
	}

	private function loadSettings() : void {
		$config = SafeConfig::load($this, "config.yml");
		$this->settings = Settings::fromConfig($config, $this->getLogger());
		$this->guard = new Guard($this->registry, $this->settings->rules);
		$this->economy = EconomyBridge::fromConfig($config->section("economy"), $this->getServer(), $this->getLogger());
		if(!$this->economy->isAvailable()){
			$this->getLogger()->warning("Economia não configurada ou indisponível (seção 'economy' do config.yml): terrenos com preço maior que 0 não podem ser comprados nem renovados até ela ser configurada. Proteção, menus e comandos de administração funcionam normalmente.");
		}
	}

	private function startWatcher() : void {
		$this->watchTask?->cancel();
		$this->watchTask = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
			$this->watcher->tick();
		}), $this->settings->checkInterval * 20);
	}

	/** Grava os terrenos (de forma assíncrona). Chamado a cada alteração. */
	public function persist() : void {
		$this->storage->saveAsync($this->registry);
	}

	public function actorOf(Player $player) : Actor {
		return new Actor($player->getName(), $player->getXuid(), $player->hasPermission(self::PERMISSION_BYPASS));
	}

	/** Mensagem com o prefixo configurado. */
	public function message(string $text) : string {
		return $this->settings->prefix . $text;
	}

	public function getSettings() : Settings { return $this->settings; }

	public function getEconomy() : EconomyBridge { return $this->economy; }

	public function getRegistry() : PlotRegistry { return $this->registry; }

	public function getGuard() : Guard { return $this->guard; }

	public function getService() : PlotService { return $this->service; }

	public function getWiper() : ContainerWiper { return $this->wiper; }

	public function getWatcher() : LeaseWatcher { return $this->watcher; }

	public function getNotices() : Notices { return $this->notices; }

	public function getMenus() : PlotMenus { return $this->menus; }
}
