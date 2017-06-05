<?php

namespace SQL;
use kernel;
use mysqli;

class MySQLManager extends \Core\Module
{
	public $connection = null;
	private $host      = 'localhost';
	private $port      = 3306;
	private $username  = null;
	private $password  = null;
	private $database  = null;

	/**
	 * Constructor.
	 *
	 * Can set parameters from an array that can contain following keys:
	 *  - host (default localhost)
	 *  - port (default 3306)
	 *  - username (required)
	 *  - password (required)
	 *  - database
	 *
	 * @param $parameters optional array of connection parameters
	 */
	public function __construct($parameters = null)
	{
		if (is_array($parameters))
		{
			/* get parameters from given array */
			$this->host     = isset($parameters['host']) ? $parameters['host'] : 'localhost';
			$this->port     = isset($parameters['port']) ? intval($parameters['port']) : 3306;
			$this->username = isset($parameters['user']) ? $parameters['user'] : null;
			$this->password = isset($parameters['password']) ? $parameters['password'] : null;
			$this->database = isset($parameters['dbname']) ? $parameters['dbname'] : null;
		}
		else
		{
			$this->host     = $this->getModuleValue('host') ? $this->getModuleValue('host') : 'localhost';
			$this->port     = $this->getModuleValue('port') ? $this->getModuleValue('port') : 'localhost';
			$this->username = $this->getModuleValue('user');
			$this->password = $this->getModuleValue('password');
			$this->database = $this->getModuleValue('dbname');
		}
	}

	public function connect()
	{
		if ($this->username === null || $this->password === null)
		{
			kernel::log(LOG_ERR, 'Username or password missing.');
			return false;
		}

		$connection = @new mysqli($this->host, $this->username, $this->password, $this->database, $this->port);
		if ($connection->connect_errno)
		{
			kernel::log(LOG_ERR, 'Mysql connection failed to host ' . $this->host . ', port: ' . $this->port . ', username ' . $this->username . ' (' . $connection->connect_error . ').');
			return false;
		}
		$connection->set_charset('utf8');
		$this->connection = $connection;

		return true;
	}

	public function dbUse($database)
	{
		$err = $this->connection->select_db($database);
		if ($err === true)
		{
			$this->database = $database;
			return true;
		}
		kernel::log(LOG_ERR, 'Database not found ' . $database . '.');
		return false;
	}

	public function dbGetAll(&$results)
	{
		$query  = 'SHOW DATABASES';
		$result = $this->connection->query($query);
		if (!$result)
		{
			kernel::log(LOG_ERR, 'Query failed, unable to fetch databases.');
			return false;
		}

		$results = array();
		$skip    = array('mysql', 'information_schema', 'performance_schema');
		while (($data = $result->fetch_array(MYSQLI_NUM)))
		{
			if (in_array($data[0], $skip))
			{
				continue;
			}
			$results[] = $data[0];
		}
		$result->close();
		return true;
	}

	public function dbCreate($database)
	{
		$query  = 'CREATE DATABASE ' . $database;
		$result = $this->connection->query($query);
		if (!$result)
		{
			kernel::log(LOG_ERR, 'Query failed, unable to create new database ' . $database . '.');
			return false;
		}
		return true;
	}

	public function dbExport(&$filename)
	{
		if ($this->database === null)
		{
			throw new Exception('Cannot export, database not set.');
		}

		/* create filename if not given */
		if (!$filename)
		{
			$tmp      = $this->kernel->expand('{path:tmp}');
			$filename = tempnam($tmp, 'mysql-manager-dump');
		}
		if (!$filename)
		{
			kernel::log(LOG_ERR, 'Export failed, cannot create temporary file.');
			return false;
		}

		$cmd = 'mysqldump -h ' . $this->host . ' -P ' . $this->port;
		$cmd .= ' -u ' . escapeshellarg($this->username);
		$cmd .= ' -p' . escapeshellarg($this->password);
		$cmd .= ' ' . escapeshellarg($this->database);
		$cmd .= ' > ' . $filename;

		$ret = exec($cmd, $none, $err);
		if ($ret === false || $err !== 0)
		{
			kernel::log(LOG_ERR, 'Export failed from database ' . $this->database . '.');
			return false;
		}

		return true;
	}

	public function dbImport($filename)
	{
		if ($this->database === null)
		{
			throw new Exception('Cannot import, database not set.');
		}
		if (!file_exists($filename))
		{
			kernel::log(LOG_ERR, 'Import failed to database ' . $this->database . ', dump file ' . $filename . ' does not exist.');
			return false;
		}

		$cmd = 'mysql -h ' . $this->host . ' -P ' . $this->port;
		$cmd .= ' -u ' . escapeshellarg($this->username);
		$cmd .= ' -p' . escapeshellarg($this->password);
		$cmd .= ' -D ' . escapeshellarg($this->database);
		$cmd .= ' < ' . $filename;

		$ret = exec($cmd, $none, $err);
		if ($ret === false || $err !== 0)
		{
			kernel::log(LOG_ERR, 'Import failed to database ' . $this->database . '.');
			return false;
		}

		return true;
	}

	public function dbCopy($from, $to)
	{
		/* try to export data from source database */
		$err = $this->dbUse($from);
		if ($err !== true)
		{
			return false;
		}
		$err = $this->dbExport($filename);
		if ($err !== true)
		{
			return false;
		}

		/* change to destination database */
		$err = $this->dbUse($to);
		if ($err !== true)
		{
			/* try to create destination database */
			$err = $this->dbCreate($to);
			if ($err !== true)
			{
				unlink($filename);
				return false;
			}
			$err = $this->dbUse($to);
			if ($err !== true)
			{
				unlink($filename);
				return false;
			}
		}

		/* import data that was just exported */
		$err = $this->dbImport($filename);
		if ($err !== true)
		{
			unlink($filename);
			return false;
		}

		unlink($filename);
		return true;
	}

	public function dbGrantAll($user, $host = 'localhost')
	{
		$query  = 'GRANT ALL PRIVILEGES ON ' . $this->database . '.* TO ' . $user . '@' . $host;
		$result = $this->connection->query($query);
		if (!$result)
		{
			kernel::log(LOG_ERR, 'Query failed, unable to grant privileges to database ' . $this->database . ' (' . $query . ').');
			return false;
		}
		return true;
	}

	public function query($query, $return_data = false)
	{
		$result = $this->connection->query($query);
		if (!$result)
		{
			kernel::log(LOG_ERR, 'Query failed, database: ' . $this->database . ', query: "' . $query . '", error: ' . $this->connection->error);
			return false;
		}

		if ($return_data)
		{
			$data = array();
			while (($row = $result->fetch_assoc()))
			{
				$data[] = $row;
			}
			$result->free();
			return $data;
		}

		return true;
	}
}
