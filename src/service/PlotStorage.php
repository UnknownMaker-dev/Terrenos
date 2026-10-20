<?php

declare(strict_types=1);

namespace Unknown\Terrenos\service;

use InvalidArgumentException;
use Logger;
use Unknown\Forja\storage\JsonFile;
use Unknown\Terrenos\model\Plot;
use Unknown\Terrenos\model\PlotConflictException;
use Unknown\Terrenos\model\PlotRegistry;
use function is_array;

/**
 * Lê e grava os terrenos num arquivo JSON (gravação atômica da Forja).
 * Entradas inválidas ou sobrepostas são ignoradas com aviso, nunca derrubam o plugin;
 * como o arquivo só é regravado depois de uma alteração, o administrador ainda pode
 * corrigir o JSON manualmente antes disso.
 */
final class PlotStorage {

	private const VERSION = 1;

	public function __construct(
		private string $path,
		private Logger $logger
	) {}

	public function getPath() : string {
		return $this->path;
	}

	/**
	 * Carrega o arquivo para o registro (que é limpo antes).
	 *
	 * @return int quantidade de terrenos carregados
	 */
	public function load(PlotRegistry $registry) : int {
		$registry->clear();
		$data = JsonFile::read($this->path, $this->logger);
		$plots = $data["terrenos"] ?? [];
		if(!is_array($plots)){
			$this->logger->warning("[{$this->path}] a chave 'terrenos' não é um objeto; nenhum terreno carregado.");
			return 0;
		}
		foreach($plots as $id => $entry){
			$id = (string) $id;
			if(!is_array($entry)){
				$this->logger->warning("Terreno '$id' ignorado: dados inválidos.");
				continue;
			}
			try{
				$registry->add(Plot::fromArray($id, $entry));
			}catch(InvalidArgumentException $e){
				$this->logger->warning("Terreno '$id' ignorado: " . $e->getMessage() . ".");
			}catch(PlotConflictException $e){
				$this->logger->warning("Terreno '$id' ignorado: " . $e->getMessage());
			}
		}
		return $registry->count();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function snapshot(PlotRegistry $registry) : array {
		$plots = [];
		foreach($registry->all() as $plot){
			$plots[$plot->getId()] = $plot->toArray();
		}
		return ["versao" => self::VERSION, "terrenos" => (object) $plots];
	}

	/** Grava numa thread assíncrona (usado a cada alteração). */
	public function saveAsync(PlotRegistry $registry) : void {
		JsonFile::writeAsync($this->path, $this->snapshot($registry));
	}

	/** Grava agora (usado ao desligar). */
	public function save(PlotRegistry $registry) : bool {
		return JsonFile::tryWrite($this->path, $this->snapshot($registry), $this->logger);
	}
}
