<?php
namespace core;

use core\DatabaseException;
use core\Command;

class Connection
{
    /**
     * @var string The Data Source Name, or DSN, contains the information required to connect to the database.
     */
    public $connectionString = null;

    /**
     * @var string the username for establishing DB connection. Defaults to empty string.
     */
    public $username = null;

    /**
     * @var string the password for establishing DB connection. Defaults to empty string.
     */
    public $password = null;

    /**
     * @var boolean Whether the DB connection is established.
     */
    private $_active = false;

    /**
     * @var PDO The PDO instance, null if the connection is not established yet.
     */
    private $_pdo = null;

    /**
     * Open or close the DB connection.
     * @param boolean $value whether to open or close DB connection
     * @throws DatabaseException if connection fails
     */
    public function setActive($value)
    {
        if ($value != $this->_active) {
            if ($value) {
                $this->open();
            } else{
                $this->close();
            }
        }
    }

    /**
     * Opens DB connection if it is currently not
     * @throws DatabaseException if connection fails
     */
    protected function open()
    {
        if ($this->_pdo === null) {
            if (empty($this->connectionString)) {
                throw new DatabaseException('DSN不能为空！');
            }
            try {
                $this->_pdo = new \PDO($this->connectionString, $this->username, $this->password);
                $this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $this->_active = true;
            } catch(\PDOException $e) {
                throw new DatabaseException('打开数据库连接错误: ' . $e->getMessage());
            }
        }
    }

    /**
     * Closes the currently active DB connection.
     * It does nothing if the connection is already closed.
     */
    protected function close()
    {
        $this->_pdo = null;
        $this->_active = false;
    }

    /**
     * Returns the PDO instance, null if the connection is not established yet
     * @return PDO the PDO instance.
     */
    public function getPdoInstance()
    {
        return $this->_pdo;
    }

    /**
     * Creates a command for execution.
     * @param string $query the DB query to be executed.
     * @return Command the command for execution.
     */
    public function createCommand($query = null)
    {
        $this->setActive(true);
        return new Command($this, $query);
    }
}
