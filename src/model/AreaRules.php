<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use InvalidArgumentException;
use Unknown\Forja\region\Cuboid;
use function abs;

/**
 * Ajustes da área de um terreno novo: limites de coordenadas e altura.
 */
final class AreaRules {

	/** Borda do mundo do Minecraft. */
	public const MAX_COORDINATE = 30_000_000;

	private function __construct() {}

	/**
	 * Valida a área e, se `$fullHeight`, estende do fundo ao topo do mundo
	 * (`$worldMinY` a `$worldMaxY`, inclusivos). Sem altura cheia, a faixa
	 * informada precisa caber no mundo.
	 *
	 * @throws InvalidArgumentException com a mensagem para o administrador
	 */
	public static function prepare(Cuboid $area, bool $fullHeight, int $worldMinY, int $worldMaxY) : Cuboid {
		foreach([$area->getMinX(), $area->getMaxX(), $area->getMinZ(), $area->getMaxZ()] as $coordinate){
			if(abs($coordinate) > self::MAX_COORDINATE){
				throw new InvalidArgumentException("Coordenadas fora do mundo (limite ±" . self::MAX_COORDINATE . ")");
			}
		}
		if($fullHeight){
			return new Cuboid($area->getWorld(), $area->getMinX(), $worldMinY, $area->getMinZ(), $area->getMaxX(), $worldMaxY, $area->getMaxZ());
		}
		if($area->getMinY() < $worldMinY || $area->getMaxY() > $worldMaxY){
			throw new InvalidArgumentException("A altura precisa ficar entre $worldMinY e $worldMaxY");
		}
		return $area;
	}
}
