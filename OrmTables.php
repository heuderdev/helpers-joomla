<?php

defined('_JEXEC') or die;

require_once __DIR__ . '/OrmBase.php';

class OrmTables
{
	protected static $instancias = [];

	public static function table($table, array $opcoes = [])
	{
		$table = trim((string) $table);

		if ($table === '') {
			throw new InvalidArgumentException(
				'O nome da tabela é obrigatório.'
			);
		}

		return new OrmBase($table, $opcoes);
	}

	public static function make($table, array $opcoes = [])
	{
		return self::table($table, $opcoes);
	}

	public static function get($nome, $table = null, array $opcoes = [])
	{
		$nome = trim((string) $nome);

		if ($nome === '') {
			throw new InvalidArgumentException(
				'O nome da instância ORM é obrigatório.'
			);
		}

		if (isset(self::$instancias[$nome])) {
			return clone self::$instancias[$nome];
		}

		if ($table === null || trim((string) $table) === '') {
			throw new InvalidArgumentException(
				'A tabela deve ser informada ao registrar a instância ORM "' . $nome . '".'
			);
		}

		self::register($nome, $table, $opcoes);

		return clone self::$instancias[$nome];
	}

	public static function register($nome, $table, array $opcoes = [])
	{
		$nome = trim((string) $nome);
		$table = trim((string) $table);

		if ($nome === '') {
			throw new InvalidArgumentException(
				'O nome da instância ORM é obrigatório.'
			);
		}

		if ($table === '') {
			throw new InvalidArgumentException(
				'O nome da tabela é obrigatório.'
			);
		}

		self::$instancias[$nome] = new OrmBase($table, $opcoes);

		return clone self::$instancias[$nome];
	}

	public static function has($nome)
	{
		return isset(self::$instancias[(string) $nome]);
	}

	public static function forget($nome)
	{
		$nome = trim((string) $nome);

		if (! isset(self::$instancias[$nome])) {
			return false;
		}

		unset(self::$instancias[$nome]);

		return true;
	}

	public static function flush()
	{
		self::$instancias = [];
	}

	public static function all()
	{
		return array_keys(self::$instancias);
	}
}