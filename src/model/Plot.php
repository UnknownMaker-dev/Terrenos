<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use InvalidArgumentException;
use Unknown\Forja\region\Cuboid;
use function count;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Um terreno: área fixa, preço do aluguel, dono (opcional) com vencimento,
 * jogadores confiáveis e bandeiras públicas.
 *
 * Donos e confiáveis são identificados pelo nome em minúsculas; o xuid é guardado
 * quando conhecido e, se os dois lados tiverem xuid, é ele que decide (um jogador
 * que pegou o nome de outro não herda o terreno, e quem trocou de nome continua dono).
 */
final class Plot {

	public const ID_PATTERN = '/^[a-z0-9_-]{1,32}$/';
	public const MAX_NAME_LENGTH = 48;

	private ?string $owner = null;
	private string $ownerDisplay = "";
	private string $ownerXuid = "";
	/** Momento em que o aluguel vence (Unix); null com dono = sem vencimento. */
	private ?int $expiresAt = null;
	/** @var array<string, array{display: string, xuid: string}> nome em minúsculas => dados */
	private array $members = [];
	/** @var array<string, bool> valor da Flag => ligada */
	private array $flags;
	/** Contêineres ainda precisam ser esvaziados (mundo estava descarregado na liberação). */
	private bool $wipePending = false;

	/**
	 * @param array<string, bool> $flags bandeiras iniciais (as ausentes ficam desligadas)
	 */
	public function __construct(
		private string $id,
		private string $name,
		private Cuboid $area,
		private float $price,
		private int $createdAt,
		array $flags = []
	) {
		if(self::normalizeId($id) !== $id){
			throw new InvalidArgumentException("Id de terreno inválido: '$id'");
		}
		$this->setName($name);
		$this->setPrice($price);
		$this->flags = Flag::allOff();
		$this->resetFlags($flags);
	}

	/** Id em minúsculas se for válido (letras, números, - e _; até 32), senão null. */
	public static function normalizeId(string $id) : ?string {
		$id = strtolower(trim($id));
		return preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
	}

	public function getId() : string { return $this->id; }

	public function getName() : string { return $this->name; }

	public function setName(string $name) : void {
		$name = trim($name);
		if($name === "" || mb_strlen($name, "UTF-8") > self::MAX_NAME_LENGTH){
			throw new InvalidArgumentException("O nome do terreno precisa ter de 1 a " . self::MAX_NAME_LENGTH . " caracteres");
		}
		$this->name = $name;
	}

	public function getArea() : Cuboid { return $this->area; }

	public function getWorld() : string { return $this->area->getWorld(); }

	public function getPrice() : float { return $this->price; }

	public function setPrice(float $price) : void {
		if(!is_finite($price) || $price < 0){
			throw new InvalidArgumentException("Preço inválido: $price");
		}
		$this->price = $price;
	}

	public function getCreatedAt() : int { return $this->createdAt; }

	// ---- dono ----

	public function hasOwner() : bool {
		return $this->owner !== null;
	}

	/** Nome do dono em minúsculas. */
	public function getOwner() : ?string {
		return $this->owner;
	}

	/** Nome do dono como ele digita (para exibir). */
	public function getOwnerDisplay() : string {
		return $this->ownerDisplay !== "" ? $this->ownerDisplay : (string) $this->owner;
	}

	public function getOwnerXuid() : string {
		return $this->ownerXuid;
	}

	public function getExpiresAt() : ?int {
		return $this->expiresAt;
	}

	public function setExpiresAt(?int $expiresAt) : void {
		$this->expiresAt = $expiresAt;
	}

	/**
	 * Passa o terreno para um novo dono. Os confiáveis do dono anterior são removidos.
	 *
	 * @param int|null $expiresAt vencimento; null = sem vencimento
	 */
	public function assignOwner(string $displayName, string $xuid, ?int $expiresAt) : void {
		$lower = strtolower(trim($displayName));
		if($lower === ""){
			throw new InvalidArgumentException("Nome de dono vazio");
		}
		if($this->owner !== $lower){
			$this->members = [];
		}
		unset($this->members[$lower]);
		$this->owner = $lower;
		$this->ownerDisplay = trim($displayName);
		$this->ownerXuid = $xuid;
		$this->expiresAt = $expiresAt;
	}

	/**
	 * Devolve o terreno à venda: sem dono, sem confiáveis e bandeiras no padrão.
	 *
	 * @param array<string, bool> $defaultFlags
	 */
	public function release(array $defaultFlags) : void {
		$this->owner = null;
		$this->ownerDisplay = "";
		$this->ownerXuid = "";
		$this->expiresAt = null;
		$this->members = [];
		$this->resetFlags($defaultFlags);
	}

	public function isOwner(Actor $actor) : bool {
		return $this->owner !== null && self::sameIdentity($this->owner, $this->ownerXuid, $actor);
	}

	// ---- confiáveis ----

	public function isMember(Actor $actor) : bool {
		$entry = $this->members[$actor->getName()] ?? null;
		if($entry !== null && self::sameIdentity($actor->getName(), $entry["xuid"], $actor)){
			return true;
		}
		if($actor->getXuid() !== ""){
			foreach($this->members as $member){
				if($member["xuid"] === $actor->getXuid()){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @return array<string, array{display: string, xuid: string}>
	 */
	public function getMembers() : array {
		return $this->members;
	}

	/** @return bool false se já era confiável ou se é o próprio dono */
	public function addMember(string $displayName, string $xuid = "") : bool {
		$lower = strtolower(trim($displayName));
		if($lower === "" || $lower === $this->owner || isset($this->members[$lower])){
			return false;
		}
		$this->members[$lower] = ["display" => trim($displayName), "xuid" => $xuid];
		return true;
	}

	public function removeMember(string $name) : bool {
		$lower = strtolower(trim($name));
		if(!isset($this->members[$lower])){
			return false;
		}
		unset($this->members[$lower]);
		return true;
	}

	/**
	 * Atualiza nome/xuid guardados quando o jogador entra: grava o xuid que faltava e
	 * acompanha a troca de nome de quem tem o mesmo xuid.
	 *
	 * @return bool true se algo mudou
	 */
	public function refreshIdentity(string $displayName, string $xuid) : bool {
		$lower = strtolower($displayName);
		$changed = false;
		$actor = new Actor($displayName, $xuid);

		if($this->owner !== null && $this->isOwner($actor)){
			if($this->owner !== $lower || $this->ownerDisplay !== $displayName || ($this->ownerXuid === "" && $xuid !== "")){
				unset($this->members[$lower]);
				$this->owner = $lower;
				$this->ownerDisplay = $displayName;
				if($xuid !== ""){
					$this->ownerXuid = $xuid;
				}
				$changed = true;
			}
		}

		foreach($this->members as $name => $member){
			$matchesXuid = $xuid !== "" && $member["xuid"] === $xuid;
			$matchesName = $name === $lower && ($member["xuid"] === "" || $xuid === "" || $member["xuid"] === $xuid);
			if(!$matchesXuid && !$matchesName){
				continue;
			}
			if($name !== $lower || $member["display"] !== $displayName || ($member["xuid"] === "" && $xuid !== "")){
				unset($this->members[$name]);
				if($lower !== $this->owner){
					$this->members[$lower] = ["display" => $displayName, "xuid" => $xuid !== "" ? $xuid : $member["xuid"]];
				}
				$changed = true;
			}
			break;
		}
		return $changed;
	}

	// ---- bandeiras ----

	public function getFlag(Flag $flag) : bool {
		return $this->flags[$flag->value] ?? false;
	}

	public function setFlag(Flag $flag, bool $value) : void {
		$this->flags[$flag->value] = $value;
	}

	/**
	 * @return array<string, bool>
	 */
	public function getFlags() : array {
		return $this->flags;
	}

	/**
	 * @param array<string, bool> $flags
	 */
	public function resetFlags(array $flags) : void {
		foreach(Flag::cases() as $flag){
			$this->flags[$flag->value] = $flags[$flag->value] ?? false;
		}
	}

	public function isWipePending() : bool {
		return $this->wipePending;
	}

	public function setWipePending(bool $pending) : void {
		$this->wipePending = $pending;
	}

	// ---- serialização ----

	/**
	 * @return array<string, mixed>
	 */
	public function toArray() : array {
		$data = [
			"nome" => $this->name,
			"area" => $this->area->toArray(),
			"preco" => $this->price,
			"criado-em" => $this->createdAt,
			"dono" => null,
			"confiaveis" => [],
			"bandeiras" => $this->flags,
			"limpeza-pendente" => $this->wipePending
		];
		if($this->owner !== null){
			$data["dono"] = [
				"nome" => $this->owner,
				"exibicao" => $this->ownerDisplay,
				"xuid" => $this->ownerXuid,
				"vence-em" => $this->expiresAt
			];
		}
		foreach($this->members as $name => $member){
			$data["confiaveis"][$name] = ["exibicao" => $member["display"], "xuid" => $member["xuid"]];
		}
		return $data;
	}

	/**
	 * @param array<mixed> $data
	 * @throws InvalidArgumentException com o motivo, se os dados forem inválidos
	 */
	public static function fromArray(string $id, array $data) : self {
		$normalized = self::normalizeId($id);
		if($normalized === null){
			throw new InvalidArgumentException("id inválido");
		}
		$area = is_array($data["area"] ?? null) ? Cuboid::fromArray($data["area"]) : null;
		if($area === null){
			throw new InvalidArgumentException("área inválida");
		}
		$price = $data["preco"] ?? 0;
		if(!is_int($price) && !is_float($price)){
			throw new InvalidArgumentException("preço inválido");
		}
		$name = is_string($data["nome"] ?? null) && trim($data["nome"]) !== "" ? $data["nome"] : $normalized;
		$createdAt = is_int($data["criado-em"] ?? null) ? $data["criado-em"] : 0;

		$flags = [];
		if(is_array($data["bandeiras"] ?? null)){
			foreach($data["bandeiras"] as $key => $value){
				$flag = Flag::parse((string) $key);
				if($flag !== null && is_bool($value)){
					$flags[$flag->value] = $value;
				}
			}
		}

		$plot = new self($normalized, mb_substr($name, 0, self::MAX_NAME_LENGTH, "UTF-8"), $area, (float) $price, $createdAt, $flags);

		$owner = $data["dono"] ?? null;
		if(is_array($owner)){
			$ownerName = $owner["exibicao"] ?? $owner["nome"] ?? null;
			if(!is_string($ownerName) || trim($ownerName) === ""){
				throw new InvalidArgumentException("dono sem nome");
			}
			if(is_string($owner["nome"] ?? null) && strtolower($ownerName) !== strtolower($owner["nome"])){
				$ownerName = $owner["nome"];
			}
			$expires = $owner["vence-em"] ?? null;
			if($expires !== null && !is_int($expires)){
				throw new InvalidArgumentException("vencimento inválido");
			}
			$plot->assignOwner($ownerName, is_string($owner["xuid"] ?? null) ? $owner["xuid"] : "", $expires);
		}

		if(is_array($data["confiaveis"] ?? null)){
			foreach($data["confiaveis"] as $name => $member){
				$display = is_array($member) && is_string($member["exibicao"] ?? null) ? $member["exibicao"] : (string) $name;
				if(strtolower($display) !== strtolower((string) $name)){
					$display = (string) $name;
				}
				$xuid = is_array($member) && is_string($member["xuid"] ?? null) ? $member["xuid"] : "";
				$plot->addMember($display, $xuid);
			}
		}

		$plot->setWipePending(($data["limpeza-pendente"] ?? false) === true);
		return $plot;
	}

	/**
	 * Mesmo jogador? Com xuid dos dois lados, o xuid decide; senão, o nome.
	 */
	private static function sameIdentity(string $storedName, string $storedXuid, Actor $actor) : bool {
		if($storedXuid !== "" && $actor->getXuid() !== ""){
			return $storedXuid === $actor->getXuid();
		}
		return $storedName === $actor->getName();
	}

	public function memberCount() : int {
		return count($this->members);
	}
}
