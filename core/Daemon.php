<?php
namespace core;

use core\Connection;

abstract class Daemon
{
    /**
     * @var integer 进程ID文件
     */
    public $pidFile = null;

    /**
     * @var integer 日志路径
     */
    public $logPath = null;

    /**
     * @var integer 最大工作数
     */
    public $maxWorkerCount = 1;

    /**
     * @var integer 扫描时间
     */
    public $scanInterval = 10;

    /**
     * @var integer 日志文件大小
     */
    public $maxLogSize = 5242880; // 5*1024*1024

    /**
     * @var Connection 数据库连接
     */
    protected $db = null;

    /**
     * @var integer 进程ID
     */
    protected $pid = null;

    /**
     * @var integer 工作进程ID
     */
    protected $workers = array();

    /**
     * 运行
     */
    public function run()
    {
        error_reporting(E_ALL);
        set_error_handler(array($this, 'errorHandler'));
        set_exception_handler(array($this, 'exceptionHandler'));

        $this->checkEnvironment();
        $this->checkNotRunning();
        $this->daemonize();
        $this->installSignal();
        while (true) {
            $count = count($this->workers);
            $task = $this->task();
            if ($task && $count < $this->maxWorkerCount) {
                $this->log("开始！\n" . print_r($task, true), 'info');
                $this->start($task);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->log('fork子进程错误！');
                    exit(1);
                } elseif ($pid === 0) {
                    $this->db = null;
                    $this->log("执行！\n" . print_r($task, true), 'info');
                    if ($this->work($task)) {
                        $this->log("执行成功！\n" . print_r($task, true), 'info');
                        $this->over($task);
                        $this->afterSuccess();
                    } else {
                        $this->log("执行失败！\n" . print_r($task, true));
                        $this->fail($task);
                    }
                    exit(0);
                } else {
                    $this->workers[$pid] = 1;
                }
            } else if (!$task && $count === 0) {
                posix_kill($this->pid, SIGTERM);
            }
            sleep($this->scanInterval);
            $this->db = null;
        }
    }

    /**
     * 守护进程化
     */
    protected function daemonize()
    {
        set_time_limit(0);
        umask(0);
        $pid = pcntl_fork();
        if ($pid === -1) {
            trigger_error('不能fork子进程！', E_USER_ERROR);
        } elseif ($pid !== 0) { // 父进程
            exit(0);
        }
        if (posix_setsid() === -1) {
            trigger_error('设置会话主进程错误！', E_USER_ERROR);
        }
        if (!pcntl_signal(SIGHUP, SIG_IGN)) {
            trigger_error('信号SIGHUP不能被忽略！', E_USER_ERROR);
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            trigger_error('不能fork子进程！', E_USER_ERROR);
        } elseif ($pid !== 0) { // 父进程
            exit(0);
        }
        if (!chdir('/')) {
            trigger_error('切换工作目录到/错误！', E_USER_ERROR);
        }
        $this->pid = posix_getpid();
        $this->writePidFile();
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $this->log('启动守护进程成功，PID=' . $this->pid . '！', 'info');
    }

    /**
     * 写进程PID文件
     */
    protected function writePidFile()
    {
        $handler = @fopen($this->pidFile, 'w');
        if ($handler === false) {
            trigger_error('进程PID文件打开错误！', E_USER_ERROR);
        }
        if (flock($handler, LOCK_EX)) {
            $count = fwrite($handler, $this->pid);
            flock($handler, LOCK_UN);
            fclose($handler);
            if ($count === false) {
                trigger_error('进程PID文件写入错误！', E_USER_ERROR);
            }
        } else {
            trigger_error('获取进程PID文件锁错误！', E_USER_ERROR);
        }
    }

    /**
     * 安装信号
     */
    protected function installSignal()
    {
        pcntl_signal(SIGCHLD, array($this, 'workerExitHandler'));
        pcntl_signal(SIGTERM, array($this, 'daemonExitHandler'));
    }

    /**
     * 工作进程退出处理
     */
    public function workerExitHandler()
    {
        if ($this->pid === posix_getpid()) {
            while(($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                unset($this->workers[$pid]);
            }
        }
    }

    /**
     * 守护进程退出处理
     */
    public function daemonExitHandler()
    {
        if ($this->pid === posix_getpid()) {
            foreach ($this->workers as $pid => $info) {
                if (!posix_kill($pid, SIGTERM) && posix_kill($pid, 0)) {
                    $this->log('杀死工作进程错误，PID=' . $pid . '！');
                }
            }
            if (file_exists($this->pidFile)) {
                unlink($this->pidFile);
            }
            exit(1);
        }
    }

    /**
     * 检查运行
     */
    protected function checkNotRunning()
    {
        if (file_exists($this->pidFile)) {
            $handler = @fopen($this->pidFile, 'r');
            if ($handler === false) {
                trigger_error('打开进程PID文件失败' . $this->pidFile . '！', E_USER_ERROR);
            }
            if (flock($handler, LOCK_EX)) {
                $pid = trim(fread($handler, filesize($this->pidFile)));
                flock($handler, LOCK_UN);
                fclose($handler);
                if ($pid !== '' && posix_kill($pid, 0)) {
                    trigger_error('进程已经在运行，PID=' . $pid . '！', E_USER_ERROR);
                }
            } else {
                trigger_error('取进程PID文件锁错误！', E_USER_ERROR);
            }
        }
    }

    /**
     * 检查环境
     */
    protected function checkEnvironment()
    {
        if (!isset($this->pidFile)) {
            trigger_error('必须指定进程PID文件！', E_USER_ERROR);
        }

        if (!isset($this->logPath)) {
            trigger_error('必须指定日志目录！', E_USER_ERROR);
        }

        if (substr(php_sapi_name(), 0, 3) !== 'cli') {
            trigger_error('守护进程只运行在CLI环境！', E_USER_ERROR);
        }
    }

    /**
     * 写日志
     * @param string $message
     * @param string $type, 'err' or 'info'
     * @return boolean
     */
    protected function log($message, $type = 'err')
    {
        if (!file_exists($this->logPath) || !is_writable($this->logPath)) {
            echo '日志目录不存在或不可写' . $this->logPath . '！';
            exit(1);
        }

        $time = date('[Y-m-d H:i:s]');
        $date = date('Y_m_d');

        $target = $this->logPath;
        switch ($type) {
            case 'info':
                $target .= 'daeInfo_' . $date . '.log';
                break;
            default:
                $target .= 'daeErr_' . $date . '.log';
                break;
        }

        if (file_exists($target) && filesize($target) >= $this->maxLogSize) {
            $filename = substr(basename($target), 0, strrpos(basename($target), '.log'))
                . '_' . time() . '.log';
            rename($target, dirname($target) . '/' . $filename);
        }

        clearstatcache();
        return error_log($time . get_class($this) . " " . posix_getpid() . " $message\n", 3, $target);
    }

    /**
     * 错误处理
     * @param integer $errno
     * @param string $errstr
     * @param string errfile
     * @param integer $errline
     * @param array $errcontext
     * @param Exception $e
     * @return boolean
     */
    public function errorHandler($errno, $errstr, $errfile, $errline, $errcontext = null, \Exception $e = null)
    {
        if (($errno & error_reporting()) == 0) {
            return true;
        }
        $fatal = false;
        switch ($errno) {
            case -1:
                // Custom - Works with the exceptionHandler exception handler
                $fatal = true;
                $errors = 'Exception';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $errors = 'Notice';
                break;
            case E_WARNING:
            case E_USER_WARNING:
                $errors = 'Warning';
                break;
            case E_ERROR:
            case E_USER_ERROR:
                $fatal = true;
                $errors = 'Fatal Error';
                break;
            default:
                $errors = 'Unknown';
                break;
        }
        $message = sprintf('PHP %s: %s in %s on line %d', $errors, $errstr, $errfile, $errline);
        if (!$e) {
            $e = new \Exception();
        }
        $this->log($message . PHP_EOL . $e->getTraceAsString());
        if ($fatal) {
            exit(1);
        }
        return true;
    }

    /**
     * 异常处理
     * @param Exception $e
     */
    public function exceptionHandler(\Exception $e)
    {
        $this->errorHandler(-1, $e->getMessage(), $e->getFile(), $e->getLine(), null, $e);
    }

    /**
     * 取数据库连接
     * return Connection
     */
    public function getDb()
    {
        if ($this->db === null) {
            $this->db = new Connection();
            $conf = include(ROOT . 'conf/database.php');
            foreach ($conf as $key => $value) {
                $this->db->$key = $value;
            }
            $this->db->setActive(true);
        }
        return $this->db;
    }

    /**
     * 任务
     */
    abstract protected function task();

    /**
     * 标记开始
     */
    abstract protected function start($task);

    /**
     * 工作
     */
    abstract protected function work(&$task);

    /**
     * 标记结束
     */
    abstract protected function over($task);

    /**
     * 标记错误
     */
    protected function fail($task)
    {
    }

    /**
     * 成功后调用
     */
    protected function afterSuccess()
    {
    }
}
