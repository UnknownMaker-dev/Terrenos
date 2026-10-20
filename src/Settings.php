<?php

declare(strict_types=1);

namespace Unknown\Terrenos;

use Logger;
use Unknown\Forja\config\ConfigView;
use Unknown\Forja\util\Duration;
use Unknown\Forja\util\Text;
use Unknown\Terrenos\model\Flag;
use Unknown\Terrenos\model\LeaseRules;
use function array_map;
use function implode;
use function strtolower;
use function trim;

/**
 * Valores do config.yml já validados. Valor inválido gera aviso no console e usa o padrão.
 */
final class Settings {

	public string $prefix;
	public float $defaultPrice;
	public bool $fullHeight;
	public int $maxPerPlayer;
	public int $maxMembers;
	/** @var array<string, bool> */
	public array $defaultFlags;
	public LeaseRules $rules;
	public int $reminderInterval;
	public int $checkInterval;
	public bool $clearContainersOnRelease;
	public bool $fireInside;
	public bool $explosionsInside;
	/** @var array<string, true> ids de rede em minúsculas */
	public array $unprotectedEntities;
	public float $noticeCooldown;

	public static function fromConfig(ConfigView $config, Logger $logger) : self {
		$settings = new self();
		$settings->prefix = Text::color($config->string("prefix", "&6[Terrenos]&r "));

		$plots = $config->section("plots");
		$settings->defaultPrice = $plots->float("default-price", 10000.0, 0.0, 1e15);
		$settings->fullHeight = $plots->bool("full-height", true);
		$settings->maxPerPlayer = $plots->int("max-per-player", 3, 0, 1000);
		$settings->maxMembers = $plots->int("max-members", 10, 0, 1000);

		$flags = Flag::allOff();
		$flagSection = $plots->section("default-flags");
		foreach($flagSection->keys() as $key){
			$flag = Flag::parse($key);
			if($flag === null){
				$logger->warning("[config.yml] bandeira desconhecida em plots.default-flags: '$key' (use: " . implode(", ", array_map(static fn(Flag $f) => $f->value, Flag::cases())) . ")");
				continue;
			}
			$flags[$flag->value] = $flagSection->bool($key, false);
		}
		$settings->defaultFlags = $flags;

		$rent = $config->section("rent");
		$duration = self::duration($config, "rent.duration", 30 * 86400, $logger, 1);
		$maxAhead = self::duration($config, "rent.max-ahead", 90 * 86400, $logger, 0);
		if($maxAhead > 0 && $maxAhead < $duration){
			$logger->warning("[config.yml] rent.max-ahead (" . Duration::format($maxAhead) . ") é menor que rent.duration (" . Duration::format($duration) . "); usando rent.duration como limite.");
			$maxAhead = $duration;
		}
		$settings->rules = new LeaseRules(
			$duration,
			self::duration($config, "rent.warn-before", 3 * 86400, $logger, 0),
			self::duration($config, "rent.grace-period", 3 * 86400, $logger, 0),
			$maxAhead
		);
		$settings->reminderInterval = self::duration($config, "rent.reminder-interval", 1800, $logger, 60);
		$settings->checkInterval = self::duration($config, "rent.check-interval", 60, $logger, 5);
		$settings->clearContainersOnRelease = $rent->bool("clear-containers-on-release", false);

		$protection = $config->section("protection");
		$settings->fireInside = $protection->bool("fire-inside", false);
		$settings->explosionsInside = $protection->bool("explosions-inside", false);
		$settings->unprotectedEntities = [];
		foreach($protection->stringList("unprotected-entities", ["minecraft:zombie"]) as $id){
			$id = strtolower(trim($id));
			if($id !== ""){
				$settings->unprotectedEntities[$id] = true;
			}
		}
		$settings->noticeCooldown = $protection->float("notice-cooldown", 1.5, 0.0, 60.0);

		return $settings;
	}

	/**
	 * Lê uma duração em texto ("30d", "12h"...) ou número de segundos.
	 */
	private static function duration(ConfigView $config, string $path, int $default, Logger $logger, int $min) : int {
		if(!$config->has($path)){
			return $default;
		}
		$text = $config->string($path, Duration::format($default));
		$seconds = Duration::parse($text);
		if($seconds === null || $seconds < $min){
			$logger->warning("[{$config->getSourceName()}] chave '$path': duração inválida \"$text\"" . ($min > 0 ? " (mínimo " . Duration::format($min) . ")" : "") . "; usando " . Duration::format($default));
			return $default;
		}
		return $seconds;
	}
}
