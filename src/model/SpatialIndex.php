<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use Unknown\Forja\region\Cuboid;
use function array_keys;
use function array_map;
use function count;

/**
 * Índice espacial simples: cada mundo é dividido em células de 16×16 blocos (o
 * tamanho de um chunk) e cada célula lista os terrenos que a tocam. Uma consulta
 * por posição só olha os terrenos daquela célula, em vez de varrer todos.
 *
 * Áreas enormes (mais células que {@see MAX_CELLS}) não são espalhadas célula a
 * célula: ficam numa lista à parte por mundo, sempre consultada.
 */
final class SpatialIndex {

	public const CELL_SHIFT = 4;
	public const MAX_CELLS = 4096;

	/** @var array<string, array<int, array<string, true>>> mundo => célula => ids */
	private array $cells = [];
	/** @var array<string, array<string, true>> mundo => ids de áreas grandes */
	private array $large = [];
	/** @var array<string, Cuboid> id => área indexada */
	private array $areas = [];

	public function add(string $id, Cuboid $area) : void {
		$this->remove($id);
		$this->areas[$id] = $area;
		$world = $area->getWorld();
		if(self::cellCount($area) > self::MAX_CELLS){
			$this->large[$world][$id] = true;
			return;
		}
		foreach(self::cellsOf($area) as $cell){
			$this->cells[$world][$cell][$id] = true;
		}
	}

	public function remove(string $id) : void {
		$area = $this->areas[$id] ?? null;
		if($area === null){
			return;
		}
		unset($this->areas[$id]);
		$world = $area->getWorld();
		if(isset($this->large[$world][$id])){
			unset($this->large[$world][$id]);
			if($this->large[$world] === []){
				unset($this->large[$world]);
			}
			return;
		}
		foreach(self::cellsOf($area) as $cell){
			unset($this->cells[$world][$cell][$id]);
			if(($this->cells[$world][$cell] ?? null) === []){
				unset($this->cells[$world][$cell]);
			}
		}
		if(($this->cells[$world] ?? null) === []){
			unset($this->cells[$world]);
		}
	}

	public function clear() : void {
		$this->cells = [];
		$this->large = [];
		$this->areas = [];
	}

	/**
	 * Ids dos terrenos que PODEM conter o bloco (a área ainda precisa ser conferida).
	 *
	 * @return list<string>
	 */
	public function candidatesAt(string $world, int $x, int $z) : array {
		$ids = $this->cells[$world][self::cellKey($x >> self::CELL_SHIFT, $z >> self::CELL_SHIFT)] ?? [];
		if(isset($this->large[$world])){
			$ids += $this->large[$world];
		}
		return self::idList($ids);
	}

	/**
	 * Ids dos terrenos que PODEM se sobrepor à área.
	 *
	 * @return list<string>
	 */
	public function candidatesIn(Cuboid $area) : array {
		$world = $area->getWorld();
		$ids = $this->large[$world] ?? [];
		if(isset($this->cells[$world])){
			if(self::cellCount($area) > self::MAX_CELLS){
				// área de busca enorme: mais barato olhar todos os terrenos do mundo
				foreach($this->cells[$world] as $cellIds){
					$ids += $cellIds;
				}
			}else{
				foreach(self::cellsOf($area) as $cell){
					$ids += $this->cells[$world][$cell] ?? [];
				}
			}
		}
		return self::idList($ids);
	}

	/** Quantidade de células (útil em testes). */
	public function cellCountFor(string $world) : int {
		return isset($this->cells[$world]) ? count($this->cells[$world]) : 0;
	}

	public static function cellCount(Cuboid $area) : int {
		$width = ($area->getMaxX() >> self::CELL_SHIFT) - ($area->getMinX() >> self::CELL_SHIFT) + 1;
		$depth = ($area->getMaxZ() >> self::CELL_SHIFT) - ($area->getMinZ() >> self::CELL_SHIFT) + 1;
		return $width * $depth;
	}

	/**
	 * @return \Generator<int, int>
	 */
	private static function cellsOf(Cuboid $area) : \Generator {
		$minCx = $area->getMinX() >> self::CELL_SHIFT;
		$maxCx = $area->getMaxX() >> self::CELL_SHIFT;
		$minCz = $area->getMinZ() >> self::CELL_SHIFT;
		$maxCz = $area->getMaxZ() >> self::CELL_SHIFT;
		for($cx = $minCx; $cx <= $maxCx; $cx++){
			for($cz = $minCz; $cz <= $maxCz; $cz++){
				yield self::cellKey($cx, $cz);
			}
		}
	}

	/**
	 * Ids só com dígitos viram chaves int no PHP; devolve sempre texto.
	 *
	 * @param array<int|string, true> $ids
	 * @return list<string>
	 */
	private static function idList(array $ids) : array {
		return array_map(static fn(int|string $id) : string => (string) $id, array_keys($ids));
	}

	private static function cellKey(int $cx, int $cz) : int {
		return (($cx & 0xFFFFFFFF) << 32) | ($cz & 0xFFFFFFFF);
	}
}
