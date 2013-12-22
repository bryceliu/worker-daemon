<?php
namespace core;

use core\Connection;
use core\DatabaseException;

class Command
{
    /**
     * @var Connection The connection associated with this command.
     */
    private $_connection;

    /**
     * @var string The SQL statement to be executed.
     */
    private $_text;

    /**
     * @var PDOStatement The underlying PDOStatement for this command.
     */
    private $_statement;

    /**
     * @var array The default fetch mode for PDOStatement.
     */
    private $_fetchMode = array(\PDO::FETCH_ASSOC);

    /**
     * Constructor.
     */
    public function __construct(Connection $connection, $query = null)
    {
        $this->_connection = $connection;
        $this->setText($query);
    }

    /**
     * Set the default fetch mode for this statement
     * @param mixed $mode fetch mode
     */
    public function setFetchMode($mode)
    {
        $params = func_get_args();
        $this->_fetchMode = $params;
        return $this;
    }

    /**
     * Cleans up the command and prepares for building a new query.
     * @return Command this command instance
     */
    public function reset()
    {
        $this->_text = null;
        $this->_statement = null;
        return $this;
    }

    /**
     * @return string the SQL statement to be executed
     */
    public function getText()
    {
        return $this->_text;
    }

    /**
     * Specifies the SQL statement to be executed.
     * @param string $value the SQL statement to be executed
     * @return Command this command instance
     */
    public function setText($value)
    {
        $this->_text = $value;
        $this->cancel();
        return $this;
    }

    /**
     * @return Connection the connection associated with this command
     */
    public function getConnection()
    {
        return $this->_connection;
    }

    /**
     * @return PDOStatement the underlying PDOStatement for this command
     * It could be null if the statement is not prepared yet.
     */
    public function getPdoStatement()
    {
        return $this->_statement;
    }

    /**
     * Prepares the SQL statement to be executed.
     * @throws DatabaseException if Command failed to prepare the SQL statement
     */
    public function prepare()
    {
        if ($this->_statement == null) {
            try {
                $this->_statement = $this->getConnection()->getPdoInstance()->prepare($this->getText());
            } catch(\Exception $e) {
                throw new DatabaseException('SQLprepare错误: ' . $this->getText() . PHP_EOL . $e->getMessage());
            }
        }
    }

    /**
     * Cancels the execution of the SQL statement.
     */
    public function cancel()
    {
        $this->_statement = null;
    }

    /**
     * Executes the SQL statement.
     * @return integer number of rows affected by the execution.
     * @throws DatabaseException execution failed
     */
    public function execute($params = array())
    {
        try {
            $this->prepare();
            if ($params === array()) {
                $this->_statement->execute();
            } else {
                $this->_statement->execute($params);
            }
            return $this->_statement->rowCount();
        } catch(\Exception $e) {
            throw new DatabaseException('SQL执行错误: ' . $this->getText() . PHP_EOL . print_r($params, true) . $e->getMessage());
        }
    }

    /**
     * Executes the SQL statement and returns all rows.
     * @param array $params input parameters (name=>value) for the SQL execution.
     * @return array all rows of the query result. Each array element is an array representing a row.
     * An empty array is returned if the query results in nothing.
     * @throws DatabaseException execution failed
     */
    public function queryAll($params = array())
    {
        return $this->_queryInternal('fetchAll', $this->_fetchMode, $params);
    }

    /**
     * Executes the SQL statement and returns the first row of the result.
     * @param array $params input parameters (name=>value) for the SQL execution.
     * @return mixed the first row (in terms of an array) of the query result, false if no result.
     * @throws DatabaseException execution failed
     */
    public function queryRow($params = array())
    {
        return $this->_queryInternal('fetch', $this->_fetchMode, $params);
    }

    /**
     * Executes the SQL statement and returns the value of the first column in the first row of data.
     * @param array $params input parameters (name=>value) for the SQL execution.
     * @return mixed the value of the first column in the first row of the query result. False is returned if there is no value.
     * @throws DatabaseException execution failed
     */
    public function queryScalar($params = array())
    {
        return $this->_queryInternal('fetchColumn', 0, $params);
    }

    /**
     * Executes the SQL statement and returns the first column of the result.
     * @param array $params input parameters (name=>value) for the SQL execution.
     * @return array the first column of the query result. Empty array if no result.
     * @throws DatabaseException execution failed
     */
    public function queryColumn($params = array())
    {
        return $this->_queryInternal('fetchAll', array(\PDO::FETCH_COLUMN, 0), $params);
    }

    /**
     * @param string $method method of PDOStatement to be called
     * @param mixed $mode parameters to be passed to the method
     * @param array $params input parameters (name=>value) for the SQL execution.
     * @throws DatabaseException if Command failed to execute the SQL statement
     * @return mixed the method execution result
     */
    private function _queryInternal($method, $mode, $params = array())
    {
        try {
            $this->prepare();
            if ($params === array()) {
                $this->_statement->execute();
            } else {
                $this->_statement->execute($params);
            }
            $mode = (array)$mode;
            if ($method === 'fetchAll') {
                $result = call_user_func_array(array($this->_statement, $method), $mode);
            } else {
                call_user_func_array(array($this->_statement, 'setFetchMode'), $mode);
                $result = $this->_statement->$method();
            }
            $this->_statement->closeCursor();
            return $result;
        } catch(\Exception $e) {
            throw new DatabaseException('SQL查询错误: ' . $this->getText() . PHP_EOL . print_r($params, true) . $e->getMessage());
        }
    }

}
