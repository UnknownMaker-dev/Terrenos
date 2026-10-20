<?php

declare(strict_types=1);

namespace Unknown\Terrenos\ui;

use Unknown\Forja\economy\EconomyBridge;
use Unknown\Forja\util\Duration;
use Unknown\Terrenos\model\Flag;
use Unknown\Terrenos\model\LeasePhase;
use Unknown\Terrenos\model\LeaseRules;
use Unknown\Terrenos\model\Plot;
use function array_map;
use function count;
use function date;
use function implode;
use function max;

/**
 * Textos reaproveitados por menus, comandos e avisos.
 */
final class Format {

	private function __construct() {}

	public static function date(int $timestamp) : string {
		return date("d/m/Y H:i", $timestamp);
	}

	public static function duration(int $seconds) : string {
		return Duration::format(max(0, $seconds));
	}

	public static function price(EconomyBridge $economy, float $price) : string {
		return $price <= 0 ? "grátis" : $economy->format($price);
	}

	public static function area(Plot $plot) : string {
		$a = $plot->getArea();
		return "{$a->getWorld()} ({$a->getMinX()}, {$a->getMinZ()}) até ({$a->getMaxX()}, {$a->getMaxZ()}), altura {$a->getMinY()} a {$a->getMaxY()}";
	}

	public static function size(Plot $plot) : string {
		$a = $plot->getArea();
		return ($a->getMaxX() - $a->getMinX() + 1) . "x" . ($a->getMaxZ() - $a->getMinZ() + 1);
	}

	/** Situação do aluguel em uma linha, com cores. */
	public static function lease(Plot $plot, LeaseRules $rules, int $now) : string {
		$expires = $plot->getExpiresAt();
		return match($rules->phase($plot, $now)){
			LeasePhase::VACANT => "§aà venda",
			LeasePhase::PERMANENT => "§bsem vencimento",
			LeasePhase::ACTIVE => "§fvence em " . self::date((int) $expires) . " §7(" . self::duration((int) $expires - $now) . ")",
			LeasePhase::ENDING => "§evence em " . self::date((int) $expires) . " §7(" . self::duration((int) $expires - $now) . ")",
			LeasePhase::GRACE => "§cvencido; carência até " . self::date((int) $rules->releaseAt($plot)) . " §7(" . self::duration((int) $rules->releaseAt($plot) - $now) . ")",
			LeasePhase::EXPIRED => "§cvencido; será liberado em instantes"
		};
	}

	public static function flags(Plot $plot) : string {
		$on = [];
		foreach(Flag::cases() as $flag){
			if($plot->getFlag($flag)){
				$on[] = $flag->value;
			}
		}
		return count($on) > 0 ? "§a" . implode("§7, §a", $on) : "§8nenhuma";
	}

	public static function members(Plot $plot) : string {
		$members = $plot->getMembers();
		if(count($members) === 0){
			return "§8nenhum";
		}
		return "§f" . implode("§7, §f", array_map(static fn(array $m) : string => $m["display"], $members));
	}

	/**
	 * Bloco de informações do terreno (várias linhas).
	 */
	public static function info(Plot $plot, EconomyBridge $economy, LeaseRules $rules, int $now) : string {
		$lines = [
			"§7Terreno: §f{$plot->getName()} §8({$plot->getId()})",
			"§7Dono: " . ($plot->hasOwner() ? "§f" . $plot->getOwnerDisplay() : "§8nenhum"),
			"§7Situação: " . self::lease($plot, $rules, $now),
			"§7Aluguel: §e" . self::price($economy, $plot->getPrice()) . " §7por §f" . self::duration($rules->getRentSeconds()),
			"§7Tamanho: §f" . self::size($plot) . " §7— " . self::area($plot),
			"§7Confiáveis: " . self::members($plot),
			"§7Liberado a visitantes: " . self::flags($plot)
		];
		return implode("\n", $lines);
	}
}
