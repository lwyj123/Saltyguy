<?php
namespace Workerman;

require_once __DIR__ . '/Lib/Constants.php';

use Workerman\Events\EventInterface;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Exception;

/**
 * Worker class
 * A container for listening ports
 */
class Worker
{
    /**
     * Version.
     *
     * @var string
     */
    const VERSION = '0.0.1';

    /**
     * Status starting.
     *
     * @var int
     */
    const STATUS_STARTING = 1;

    /**
     * Status running.
     *
     * @var int
     */
    const STATUS_RUNNING = 2;

    /**
     * Status shutdown.
     *
     * @var int
     */
    const STATUS_SHUTDOWN = 4;

    /**
     * After sending the restart command to the child process KILL_WORKER_TIMER_TIME seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 2;

    /**
     * Default backlog. Backlog is the maximum length of the queue of pending connections.
     *
     * @var int
     */
    const DEFAULT_BACKLOG = 1024;

    /**
     * Worker id.
     *
     * @var int
     */
    public $id = 0;

    /**
     * Name of the worker processes.
     *
     * @var string
     */
    public $name = 'none';

    /**
     * Number of worker processes.
     *
     * @var int
     */
    public $count = 1;

    /**
     * Unix user of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $user = '';

    /**
     * Unix group of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $group = '';

    /**
     * Emitted when worker processes start.
     *
     * @var callback
     */
    public $onWorkerStart = null;

    /**
     * Emitted when a socket connection is successfully established.
     *
     * @var callback
     */
    public $onConnect = null;

    /**
     * Emitted when data is received.
     *
     * @var callback
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callback
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callback
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callback
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callback
     */
    public $onBufferDrain = null;

    /**
     * Emitted when worker processes stoped.
     *
     * @var callback
     */
    public $onWorkerStop = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public $transport = 'tcp';


    /**
     * Store all connections of clients.
     *
     * @var array
     */
    public $connections = array();

    /**
     * Application layer protocol.
     *
     * @var Protocols\ProtocolInterface
     */
    public $protocol = '';

    /**
     * Root path for autoload.
     *
     * @var string
     */
    protected $_autoloadRootPath = '';


    /**
     * Stdout file.
     *
     * @var string
     */
    public static $stdoutFile = '/dev/null';

    /**
     * The file to store master process PID.
     *
     * @var string
     */
    public static $pidFile = '';

    /**
     * Log file.
     *
     * @var mixed
     */
    public static $logFile = '';

    /**
     * Global event loop.
     *
     * @var Events\EventInterface
     */
    public static $globalEvent = null;

    /**
     * The PID of master process.
     *
     * @var int
     */
    protected static $_masterPid = 0;

    /**
     * Listening socket.
     *
     * @var resource
     */
    protected $_mainSocket = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80 .
     *
     * @var string
     */
    protected $_socketName = '';

    /**
     * Context of socket.
     *
     * @var resource
     */
    protected $_context = null;

    /**
     * All worker instances.
     *
     * @var array
     */
    protected static $_workers = array();

    /**
     * All worker porcesses pid.
     * The format is like this [worker_id=>[pid=>pid, pid=>pid, ..], ..]
     *
     * @var array
     */
    protected static $_pidMap = array();

    /**
     * All worker processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static $_pidsToRestart = array();

    /**
     * Mapping from PID to worker process ID.
     * The format is like this [worker_id=>[0=>$pid, 1=>$pid, ..], ..].
     *
     * @var array
     */
    protected static $_idMap = array();

    /**
     * Current status.
     *
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    /**
     * Current eventLoop name.
     *
     * @var string
     */
    protected static $_eventLoopName = 'libevent';

    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
    );

    /**
     * 运行所有worker实例
     *
     * @return void
     */
    public static function runAll()
    {
        self::init();
        self::parseCommand();
        self::initWorkers();
        self::saveMasterPid();
        self::forkWorkers();
        self::displayUI();
        self::monitorWorkers();
    }

    /**
     * 初始化
     *
     * @return void
     */
    protected static function init()
    {
        // 获取启动文件名
        $backtrace        = debug_backtrace();
        self::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        // Pid 文件
        if (empty(self::$pidFile)) {
            self::$pidFile = __DIR__ . "/../" . str_replace('/', '_', self::$_startFile) . ".pid";
        }


        // Worker的状态初始化为Starting
        self::$_status = self::STATUS_STARTING;


        // 设置进程标题
        self::setProcessTitle('Saltyguy: master process  start_file=' . self::$_startFile);

        // 初始化 worker id.
        self::initId();

        // 初始化Timer.
        Timer::init();
    }

    /**
     * 初始化所有Worker
     *
     * @return void
     */
    protected static function initWorkers()
    {
        foreach (self::$_workers as $worker) {
            // Worker name.
            if (empty($worker->name)) {
                $worker->name = 'none';
            }

            // 获取Worker进程的用户
            if (empty($worker->user)) {
                $worker->user = self::getCurrentUser();
            }

            // 监听
            $worker->listen();
        }
    }

    /**
     * 返回worker数组
     *
     * @return array
     */
    public static function getAllWorkers()
    {
        return self::$_workers;
    }

    /**
     * Get global event-loop instance.
     *
     * @return EventInterface
     */
    public static function getEventLoop()
    {
        return self::$globalEvent;
    }

    /**
     * Init idMap.
     * return void
     */
    protected static function initId()
    {
        foreach (self::$_workers as $worker_id => $worker) {
            $new_id_map = array();
            for($key = 0; $key < $worker->count; $key++) {
                $new_id_map[$key] = isset(self::$_idMap[$worker_id][$key]) ? self::$_idMap[$worker_id][$key] : 0;
            }
            self::$_idMap[$worker_id] = $new_id_map;
        }
    }

    /**
     * 获取当前进程用户
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }

    /**
     * 界面显示
     *
     * @return void
     */
    protected static function displayUI()
    {
        self::safeEcho("Saltyguy \n");
        self::safeEcho("----------------------------------------------------------------\n");
        self::safeEcho("Press Ctrl-C to quit. Start success.\n");
    }

    /**
     * 解析指令
     * php filename.php start
     *
     * @return void
     */
    protected static function parseCommand()
    {
        global $argv;

        $start_file = $argv[0];
        if (!isset($argv[1])) {
            exit("Usage: php yourfile.php {start}\n");
        }

        // Get command.
        $command  = trim($argv[1]);
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // Start command.
        $mode = '';
        if ($command === 'start') {
            $mode = 'in DEBUG mode';
        }

        // Get master process PID.
        $master_pid      = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);

    }

    /**
     * 保存Master进程的pid
     *
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        self::$_masterPid = posix_getpid();
        @file_put_contents(self::$pidFile, self::$_masterPid);

    }

    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName()
    {
        return self::$_eventLoopName;
    }

    /**
     * 获取所有Worker的pid  pid_array[worker_id=>pid]
     *
     * @return array
     */
    protected static function getAllWorkerPids()
    {
        $pid_array = array();
        foreach (self::$_pidMap as $worker_pid_array) {
            foreach ($worker_pid_array as $worker_pid) {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function forkWorkers()
    {
        foreach (self::$_workers as $worker) {
            if (self::$_status === self::STATUS_STARTING) {
                if (empty($worker->name)) {
                    $worker->name = $worker->getSocketName();
                }

            }

            $worker->count = $worker->count <= 0 ? 1 : $worker->count;
            while (count(self::$_pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorker($worker);
            }
        }
    }

    /**
     * Fork 一个worker进程.
     *
     * @param Worker $worker
     * @throws Exception
     */
    protected static function forkOneWorker($worker)
    {
        // Get available worker id.
        $id = self::getId($worker->workerId, 0);
        if ($id === false) {
            return;
        }
        $pid = pcntl_fork();
        // 对于Master进程.
        if ($pid > 0) {
            self::$_pidMap[$worker->workerId][$pid] = $pid;
            self::$_idMap[$worker->workerId][$id]   = $pid;
        } // 对于子进程.
        elseif (0 === $pid) {
            self::$_pidMap  = array();
            self::$_workers = array($worker->workerId => $worker);
            Timer::delAll();
            self::setProcessTitle('Saltyguy: worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            $worker->setUserAndGroup();
            $worker->id = $id;
            $worker->run();
            exit(250);
        } else {
            throw new Exception("forkOneWorker fail");
        }
    }

    /**
     * 检测一个worker中是否有某个pid
     *
     * @param int $worker_id
     * @param int $pid
     */
    protected static function getId($worker_id, $pid)
    {
        return array_search($pid, self::$_idMap[$worker_id]);
    }

    /**
     * 为当前进程设置用户和用户组.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        // 获取uid
        $user_info = posix_getpwnam($this->user);
        if (!$user_info) {
            return;
        }
        $uid = $user_info['uid'];
        // 获取gid
        if ($this->group) {
            $group_info = posix_getgrnam($this->group);
            if (!$group_info) {
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // 设置uid和gid
        if ($uid != posix_getuid() || $gid != posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($user_info['name'], $gid) || !posix_setuid($uid)) {
            }
        }
    }

    /**
     * 设置进程名
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        @cli_set_process_title($title);
    }

    /**
     * 监控所有子进程
     *
     * @return void
     */
    protected static function monitorWorkers()
    {
        self::$_status = self::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            pcntl_signal_dispatch();
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;

            //等待一个子进程退出或收到一个信号
            $pid    = pcntl_wait($status, WUNTRACED);
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();
            // 如果是一个子进程退出
            if ($pid > 0) {
                // 查找是哪一个进程并调整状态
                foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                    if (isset($worker_pid_array[$pid])) {
                        $worker = self::$_workers[$worker_id];

                        // Clear process data.
                        unset(self::$_pidMap[$worker_id][$pid]);

                        // Mark id is available.
                        $id                            = self::getId($worker_id, $pid);
                        self::$_idMap[$worker_id][$id] = 0;

                        break;
                    }
                }
            } else {
                // If shutdown state and all child processes exited then master process exit.
                if (self::$_status === self::STATUS_SHUTDOWN && !self::getAllWorkerPids()) {
                    self::exitAndClearAll();
                }
            }
        }
    }

    /**
     * Exit current process.
     *
     * @return void
     */
    protected static function exitAndClearAll()
    {
        foreach (self::$_workers as $worker) {
            $socket_name = $worker->getSocketName();
        }
        @unlink(self::$pidFile);
        exit(0);
    }



    /**
     * Safe Echo.
     *
     * @param $msg
     */
    public static function safeEcho($msg)
    {
        if (!function_exists('posix_isatty') || posix_isatty(STDOUT)) {
            echo $msg;
        }
    }

    /**
     * 构造函数
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name = '', $context_option = array())
    {
        // Save all worker instances.
        $this->workerId                  = spl_object_hash($this);
        self::$_workers[$this->workerId] = $this;
        self::$_pidMap[$this->workerId]  = array();

        //获取根目录
        $backtrace                = debug_backtrace();
        $this->_autoloadRootPath = dirname($backtrace[0]['file']);

        // Context for socket.
        if ($socket_name) {
            $this->_socketName = $socket_name;
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = self::DEFAULT_BACKLOG;
            }
            $this->_context = stream_context_create($context_option);
        }

        // Set an empty onMessage callback.
        $this->onMessage = function () {
        };
    }

    /**
     * 监听端口
     *
     * @throws Exception
     */
    public function listen()
    {
        if (!$this->_socketName || $this->_mainSocket) {
            return;
        }

        // Autoload.
        Autoloader::setRootPath($this->_autoloadRootPath);

        $local_socket = $this->_socketName;
        // 获取协议核地址
        list($scheme, $address) = explode(':', $this->_socketName, 2);
        // Check application layer protocol class.
        if (!isset(self::$_builtinTransports[$scheme])) {
            if(class_exists($scheme)){
                $this->protocol = $scheme;
            } else {
                $scheme         = ucfirst($scheme);
                $this->protocol = '\\Protocols\\' . $scheme;
                if (!class_exists($this->protocol)) {
                    $this->protocol = "\\Workerman\\Protocols\\$scheme";
                    if (!class_exists($this->protocol)) {
                        throw new Exception("class \\Protocols\\$scheme not exist");
                    }
                }
            }
            $local_socket = $this->transport . ":" . $address;
        }

        // Flag.
        $flags  = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $errno  = 0;
        $errmsg = '';

        // Create an Internet or Unix domain server socket.
        $this->_mainSocket = stream_socket_server($local_socket, $errno, $errmsg, $flags, $this->_context);
        if (!$this->_mainSocket) {
            throw new Exception($errmsg);
        }


        // Non blocking.
        stream_set_blocking($this->_mainSocket, 0);

        // Register a listener to be notified when server socket is ready to read.
        if (self::$globalEvent) {
            self::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName ? lcfirst($this->_socketName) : 'none';
    }

    /**
     * Run worker instance.
     *
     * @return void
     */
    public function run()
    {
        //Update process state.
        self::$_status = self::STATUS_RUNNING;

        // Set autoload root path.
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Create a global event loop.
        if (!self::$globalEvent) {
            $eventLoopClass    = "\\Workerman\\Events\\" . ucfirst(self::getEventLoopName());
            self::$globalEvent = new $eventLoopClass;
            // 注册一个监听器来通知socket可读
            if ($this->_socketName) {
                self::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
            }
        }


        // Init Timer.
        Timer::init(self::$globalEvent);

        // Try to emit onWorkerStart callback.
        if ($this->onWorkerStart) {
            call_user_func($this->onWorkerStart, $this);
        }

        // Main loop.
        self::$globalEvent->loop();
    }


    /**
     * Stop current worker instance.
     *
     * @return void
     */
    public function stop()
    {
        // Try to emit onWorkerStop callback.
        if ($this->onWorkerStop) {
            call_user_func($this->onWorkerStop, $this);
        }
        // Remove listener for server socket.
        self::$globalEvent->del($this->_mainSocket, EventInterface::EV_READ);
        @fclose($this->_mainSocket);
    }



    /**
     * Accept a connection.
     *
     * @param resource $socket
     * @return void
     */
    public function acceptConnection($socket)
    {
        // Accept a connection on server socket.
        $new_socket = @stream_socket_accept($socket, 0, $remote_address);
        // Thundering herd.
        if (!$new_socket) {
            return;
        }

        // TcpConnection.
        $connection                         = new TcpConnection($new_socket, $remote_address);
        $this->connections[$connection->id] = $connection;
        $connection->worker                 = $this;
        $connection->protocol               = $this->protocol;
        $connection->onMessage              = $this->onMessage;
        $connection->onClose                = $this->onClose;
        $connection->onError                = $this->onError;
        $connection->onBufferDrain          = $this->onBufferDrain;
        $connection->onBufferFull           = $this->onBufferFull;

        // Try to emit onConnect callback.
        if ($this->onConnect) {
             call_user_func($this->onConnect, $connection);
        }
    }
}
