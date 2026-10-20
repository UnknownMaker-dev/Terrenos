<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use Unknown\Forja\region\Cuboid;
use function count;
use function strtolower;
use function uasort;
use function strcmp;

/**
 * Todos os terrenos carregados, com o índice espacial. Garante que não existem
 * dois terrenos com o mesmo id nem dois terrenos que se sobrepõem.
 */
final class PlotRegistry {

	/** @var array<string, Plot> */
	private array $plots = [];
	private SpatialIndex $index;

	public function __construct() {
		$this->index = new SpatialIndex();
	}

	public function get(string $id) : ?Plot {
		$normalized = Plot::normalizeId($id);
		return $normalized === null ? null : ($this->plots[$normalized] ?? null);
	}

	/**
	 * @return list<Plot> ordenados pelo id
	 */
	public function all() : array {
		$plots = $this->plots;
		uasort($plots, static fn(Plot $a, Plot $b) : int => strcmp($a->getId(), $b->getId()));
		$list = [];
		foreach($plots as $plot){
			$list[] = $plot;
		}
		return $list;
	}

	public function count() : int {
		return count($this->plots);
	}

	/** O terreno que contém o bloco, se houver (no máximo um, já que não há sobreposição). */
	public function at(string $world, int $x, int $y, int $z) : ?Plot {
		foreach($this->index->candidatesAt($world, $x, $z) as $id){
			$plot = $this->plots[$id] ?? null;
			if($plot !== null && $plot->getArea()->containsBlock($world, $x, $y, $z)){
				return $plot;
			}
		}
		return null;
	}

	/** Primeiro terreno (exceto `$ignoreId`) que tem pelo menos um bloco em comum com a área. */
	public function findOverlap(Cuboid $area, ?string $ignoreId = null) : ?Plot {
		foreach($this->index->candidatesIn($area) as $id){
			if($id === $ignoreId){
				continue;
			}
			$plot = $this->plots[$id] ?? null;
			if($plot !== null && $plot->getArea()->intersects($area)){
				return $plot;
			}
		}
		return null;
	}

	/**
	 * @throws PlotConflictException se o id já existe ou a área invade outro terreno
	 */
	public function add(Plot $plot) : void {
		if(isset($this->plots[$plot->getId()])){
			throw new PlotConflictException("Já existe um terreno com o id '{$plot->getId()}'.", null);
		}
		$overlap = $this->findOverlap($plot->getArea());
		if($overlap !== null){
			throw new PlotConflictException("A área invade o terreno '{$overlap->getId()}'.", $overlap);
		}
		$this->plots[$plot->getId()] = $plot;
		$this->index->add($plot->getId(), $plot->getArea());
	}

	public function remove(string $id) : ?Plot {
		$plot = $this->get($id);
		if($plot === null){
			return null;
		}
		unset($this->plots[$plot->getId()]);
		$this->index->remove($plot->getId());
		return $plot;
	}

	public function clear() : void {
		$this->plots = [];
		$this->index->clear();
	}

	/**
	 * Terrenos cujo dono registrado é o jogador (pelo nome em minúsculas).
	 *
	 * @return list<Plot>
	 */
	public function ownedBy(string $name) : array {
		$name = strtolower($name);
		$list = [];
		foreach($this->all() as $plot){
			if($plot->getOwner() === $name){
				$list[] = $plot;
			}
		}
		return $list;
	}

	public function getIndex() : SpatialIndex {
		return $this->index;
	}
}
