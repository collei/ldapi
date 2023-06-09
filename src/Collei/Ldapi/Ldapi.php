<?php
namespace Collei\Ldapi;

/**
 *	Encapsulates LDAP calls.
 *
 *	@author alarido <alarido.su@gmail.com>
 *	@since 2021-07-xx
 */
class Ldapi
{
	/**
	 *	@var string $server
	 */
	protected $server = '';

	/**
	 *	@var string $organization
	 */
	protected $organization = 'system';

	/**
	 *	@var resource $connection
	 */
	protected $connection = NULL;

	/**
	 *	@var bool $bind
	 */
	protected $bind = [
		'state' => false,
		'user' => '',
		'password' => '',
		'secure' => false,
	];

	/**
	 *	@var array $trees
	 */
	protected $trees = [];

	/**
	 *	Initializer
	 *
	 *	@param	string	$server
	 *	@return	self
	 */
	public function __construct(string $server)
	{
		$this->server = $server;
	}

	/**
	 *	Retrieves the LDAP server.
	 *
	 *	@return	string
	 */
	public function getServer()
	{
		return $this->server;
	}

	/**
	 *	Retrieves the underlying LDAP connection.
	 *
	 *	@return	resource
	 */
	public function getConnection()
	{
		return $this->connection;
	}

	/**
	 *	Retrieves the current orgenization.
	 *
	 *	@return	string
	 */
	public function getOrganization()
	{
		return $this->organization;
	}

	/**
	 *	Defines the current orgenization to query against.
	 *
	 *	@return	$this
	 */
	public function setOrganization($organization)
	{
		$this->organization = $organization;
		//
		return $this;
	}

	/**
	 *	Converts binary data to MSSQL Guid format
	 *
	 *	@static
	 *	@param	mixed	$binaryGUID
	 *	@return	string
	 */
	protected static function binToGUID($binaryGUID)
	{
		$unpacked = unpack('Va/v2b/n2c/Nd', $binaryGUID);
		//
		return sprintf(
			'%08X-%04X-%04X-%04X-%04X%08X',
			$unpacked['a'],
			$unpacked['b1'],
			$unpacked['b2'],
			$unpacked['c1'],
			$unpacked['c2'],
			$unpacked['d']
		);
	}

	/**
	 *	Organizes the info array returned by ldap_get_entries()
	 *
	 *	@static
	 *	@param	array	$info
	 *	@return	array
	 */
	protected static function planify(array $info)
	{
		$items = [];
		//
		foreach ($info as $k => $subInfo) {
			if (!\is_int($k)) {
				continue;
			}
			//
			$keys = [];
			$details = [];
			//
			foreach ($subInfo as $dk => $value) {
				if (\is_int($dk)) {
					$keys[$dk] = $value;
				} else {
					if (($value['count'] ?? 0) > 1) {
						$details[$dk] = [];
						foreach ($value as $ek => $subValue) {
							if ($ek !== 'count') {
								$details[$dk][] = $subValue;
							}
						}
					} else {
						if (\in_array($dk, ['objectguid','objectsid'])) {
							$details[$dk] = [
								($value[0] ?? ''),
								self::binToGUID($value[0] ?? ''),
							];
						} else {
							$details[$dk] = $value[0] ?? '';
						}
					}
				}
			}
			//
			$items[] = [
				'data' => $details,
				'keys' => $keys,
			];
		}
		//
		return $items;
	}

	/**
	 *	Initialize the communication to LDAP server (not real connection)
	 *
	 *	@return	bool
	 */
	public function connect()
	{
		if (!empty($this->server)) {
			$this->connection = @\ldap_connect($this->server);
			//
			if ($this->connection === FALSE) {
				$this->connection = NULL;
			} else {
				\ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
				\ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
				return true;
			}
		}
		//
		return false;
	}

	/**
	 *	Binds the connection to the specified user
	 *
	 *	@param	string	$userOrDN
	 *	@param	string	$password
	 *	@return	bool
	 */
	public function bind(string $userOrDN, string $password)
	{
		if ($this->isConnected()) {
			$this->bind['user'] = $userOrDN;
			$this->bind['password'] = $password;
			$this->bind['secure'] = \ldap_start_tls($this->connection);
			//
			$this->bind['state'] = @\ldap_bind(
				$this->connection, $userOrDN, $password
			);
		} elseif ($this->connect()) {
			return $this->bind($userOrDN, $password);
		}
		//
		return $this->isBound();
	}

	/**
	 *	Returns whether the given connection is/remains valid
	 *
	 *	@return	bool
	 */
	public function isConnected()
	{
		return !is_null($this->connection);
	}

	/**
	 *	Returns whether the given connection is/remains bound
	 *
	 *	@return	bool
	 */
	public function isBound()
	{
		return $this->bind['state'];	
	}

	/**
	 *	Executes a search and returns results if successful.
	 *	Returns false otherwise.
	 *
	 *	@return	bool
	 */
	public function search(string $expression, string $tree = null)
	{
		if ($this->isConnected() && $this->isBound()) {
			if (!empty($tree)) {
				if (
					$search = @\ldap_search($this->connection, $tree, $expression)
				) {
					$info = @\ldap_get_entries($this->connection, $search);
					//
					if ($info["count"] > 0) {
						return self::planify($info);
					}
				}
			}
		}
		//
		return false;
	}
}

