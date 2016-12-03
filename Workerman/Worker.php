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
    const VERSION = '3.3.6';

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
     * Status reloading.
     *
     * @var int
     */
    const STATUS_RELOADING = 8;

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
     * reloadable.
     *
     * @var bool
     */
    public $reloadable = true;

    /**
     * reuse port.
     *
     * @var bool
     */
    public $reusePort = false;

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
     * Emitted when the master process get reload signal.
     *
     * @var callback
     */
    public static $onMasterReload = null;

    /**
     * Emitted when worker processes get reload signal.
     *
     * @var callback
     */
    public $onWorkerReload = null;

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
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static $_maxSocketNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static $_maxUserNameLength = 12;


    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    /**
     * Available event loops.
     *
     * @var array
     */
    protected static $_availableEventLoops = array(
        'libevent',
        'event',
        'ev'
    );

    /**
     * Current eventLoop name.
     *
     * @var string
     */
    protected static $_eventLoopName = 'select';

    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'unix'  => 'unix',
        'ssl'   => 'tcp',
        'sslv2' => 'tcp',
        'sslv3' => 'tcp',
        'tls'   => 'tcp'
    );

    /**
     * Run all worker instances.
     *
     * @return void
     */
    public static function runAll()
    {
        self::checkSapiEnv();
        self::init();
        self::parseCommand();
        self::initWorkers();
        self::installSignal();
        self::saveMasterPid();
        self::forkWorkers();
        self::displayUI();
        self::monitorWorkers();
    }

    /**
     * 检测cli
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        }
    }

    /**
     * Init.
     *
     * @return void
     */
    protected static function init()
    {
        // Start file.
        $backtrace        = debug_backtrace();
        self::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        // Pid file.
        if (empty(self::$pidFile)) {
            self::$pidFile = __DIR__ . "/../" . str_replace('/', '_', self::$_startFile) . ".pid";
        }


        // State.
        self::$_status = self::STATUS_STARTING;


        // Process title.
        self::setProcessTitle('WorkerMan: master process  start_file=' . self::$_startFile);

        // Init data for worker id.
        self::initId();

        // Timer init.
        Timer::init();
    }

    /**
     * Init All worker instances.
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

            // Get maximum length of worker name.
            $worker_name_length = strlen($worker->name);
            if (self::$_maxWorkerNameLength < $worker_name_length) {
                self::$_maxWorkerNameLength = $worker_name_length;
            }

            // Get maximum length of socket name.
            $socket_name_length = strlen($worker->getSocketName());
            if (self::$_maxSocketNameLength < $socket_name_length) {
                self::$_maxSocketNameLength = $socket_name_length;
            }

            // Get unix user of the worker process.
            if (empty($worker->user)) {
                $worker->user = self::getCurrentUser();
            }

            // Get maximum length of unix user name.
            $user_name_length = strlen($worker->user);
            if (self::$_maxUserNameLength < $user_name_length) {
                self::$_maxUserNameLength = $user_name_length;
            }

            // Listen.
            if (!$worker->reusePort) {
                $worker->listen();
            }
        }
    }

    /**
     * Get all worker instances.
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
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI()
    {
        self::safeEcho("workerman start\n");
        self::safeEcho("----------------------------------------------------------------\n");
        self::safeEcho("Press Ctrl-C to quit. Start success.\n");
    }

    /**
     * Parse command.
     * php yourfile.php start ...
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
     * Install signal handler.
     *
     * @return void
     */
    protected static function installSignal()
    {
        // stop
        pcntl_signal(SIGINT, array('\Workerman\Worker', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\Workerman\Worker', 'signalHandler'), false);
        // status
        pcntl_signal(SIGUSR2, array('\Workerman\Worker', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * Reinstall signal handler.
     *
     * @return void
     */
    protected static function reinstallSignal()
    {
        // uninstall stop signal handler
        pcntl_signal(SIGINT, SIG_IGN, false);
        // uninstall reload signal handler
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        // uninstall  status signal handler
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        // reinstall stop signal handler
        self::$globalEvent->add(SIGINT, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall  reload signal handler
        self::$globalEvent->add(SIGUSR1, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
        // reinstall  status signal handler
        self::$globalEvent->add(SIGUSR2, EventInterface::EV_SIGNAL, array('\Workerman\Worker', 'signalHandler'));
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
                self::stopAll();
                break;
            // Reload.
            case SIGUSR1:
                self::$_pidsToRestart = self::getAllWorkerPids();
                self::reload();
                break;
        }
    }



    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        self::$_masterPid = posix_getpid();
        if (false === @file_put_contents(self::$pidFile, self::$_masterPid)) {
            throw new Exception('can not save pid to ' . self::$pidFile);
        }
    }

    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName()
    {
        if (interface_exists('\React\EventLoop\LoopInterface')) {
            return 'React';
        }
        foreach (self::$_availableEventLoops as $name) {
            if (extension_loaded($name)) {
                self::$_eventLoopName = $name;
                break;
            }
        }
        return self::$_eventLoopName;
    }

    /**
     * Get all pids of worker processes.
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
                $worker_name_length = strlen($worker->name);
                if (self::$_maxWorkerNameLength < $worker_name_length) {
                    self::$_maxWorkerNameLength = $worker_name_length;
                }
            }

            $worker->count = $worker->count <= 0 ? 1 : $worker->count;
            while (count(self::$_pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorker($worker);
            }
        }
    }

    /**
     * Fork one worker process.
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
        // For master process.
        if ($pid > 0) {
            self::$_pidMap[$worker->workerId][$pid] = $pid;
            self::$_idMap[$worker->workerId][$id]   = $pid;
        } // For child processes.
        elseif (0 === $pid) {
            if ($worker->reusePort) {
                $worker->listen();
            }

            self::$_pidMap  = array();
            self::$_workers = array($worker->workerId => $worker);
            Timer::delAll();
            self::setProcessTitle('WorkerMan: worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            $worker->setUserAndGroup();
            $worker->id = $id;
            $worker->run();
            exit(250);
        } else {
            throw new Exception("forkOneWorker fail");
        }
    }

    /**
     * Get worker id.
     *
     * @param int $worker_id
     * @param int $pid
     */
    protected static function getId($worker_id, $pid)
    {
        return array_search($pid, self::$_idMap[$worker_id]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = posix_getpwnam($this->user);
        if (!$user_info) {
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = posix_getgrnam($this->group);
            if (!$group_info) {
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid != posix_getuid() || $gid != posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($user_info['name'], $gid) || !posix_setuid($uid)) {
            }
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        }
    }

    /**
     * Monitor all child processes.
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
            $pid    = pcntl_wait($status, WUNTRACED);
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out witch worker process exited.
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
                // Is still running state then fork a new worker process.
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::forkWorkers();
                    // If reloading continue.
                    if (isset(self::$_pidsToRestart[$pid])) {
                        unset(self::$_pidsToRestart[$pid]);
                        self::reload();
                    }
                } else {
                    // If shutdown state and all child processes exited then master process exit.
                    if (!self::getAllWorkerPids()) {
                        self::exitAndClearAll();
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
            if ($worker->transport === 'unix' && $socket_name) {
                list(, $address) = explode(':', $socket_name, 2);
                @unlink($address);
            }
        }
        @unlink(self::$pidFile);
        exit(0);
    }

    /**
     * Execute reload.
     *
     * @return void
     */
    protected static function reload()
    {
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            // Set reloading state.
            if (self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN) {
                self::$_status = self::STATUS_RELOADING;
                // Try to emit onMasterReload callback.
                if (self::$onMasterReload) {
                    call_user_func(self::$onMasterReload);
                    self::initId();
                }
            }

            // Send reload signal to all child processes.
            $reloadable_pid_array = array();
            foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = self::$_workers[$worker_id];
                if ($worker->reloadable) {
                    foreach ($worker_pid_array as $pid) {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                } else {
                    foreach ($worker_pid_array as $pid) {
                        // Send reload signal to a worker process which reloadable is false.
                        posix_kill($pid, SIGUSR1);
                    }
                }
            }

            // Get all pids that are waiting reload.
            self::$_pidsToRestart = array_intersect(self::$_pidsToRestart, $reloadable_pid_array);

            // Reload complete.
            if (empty(self::$_pidsToRestart)) {
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::$_status = self::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $one_worker_pid = current(self::$_pidsToRestart);
            // Send reload signal to a worker process.
            posix_kill($one_worker_pid, SIGUSR1);
            // If the process does not exit after self::KILL_WORKER_TIMER_TIME seconds try to kill it.
            Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($one_worker_pid, SIGKILL), false);
        } // For child processes.
        else {
            $worker = current(self::$_workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload) {
                call_user_func($worker->onWorkerReload, $worker);
            }

            if ($worker->reloadable) {
                self::stopAll();
            }
        }
    }

    /**
     * Stop.
     *
     * @return void
     */
    public static function stopAll()
    {
        self::$_status = self::STATUS_SHUTDOWN;
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            $worker_pid_array = self::getAllWorkerPids();
            // Send stop signal to all child processes.
            foreach ($worker_pid_array as $worker_pid) {
                posix_kill($worker_pid, SIGINT);
                Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($worker_pid, SIGKILL), false);
            }
        } // For child processes.
        else {
            // Execute exit.
            foreach (self::$_workers as $worker) {
                $worker->stop();
            }
            exit(0);
        }
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors()
    {
        if (self::STATUS_SHUTDOWN != self::$_status) {
            $error_msg = "WORKER EXIT UNEXPECTED ";
            $errors    = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $error_msg .= self::getErrorType($errors['type']) . " {$errors['message']} in {$errors['file']} on line {$errors['line']}";
            }
        }
    }

    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch ($type) {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
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
     * Construct.
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

        // Get autoload root path.
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
     * Listen port.
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
        // Get the application layer communication protocol and listening address.
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
        } else {
            $this->transport = self::$_builtinTransports[$scheme];
        }

        // Flag.
        $flags  = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $errno  = 0;
        $errmsg = '';
        // SO_REUSEPORT.
        if ($this->reusePort) {
            stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
        }

        // Create an Internet or Unix domain server socket.
        $this->_mainSocket = stream_socket_server($local_socket, $errno, $errmsg, $flags, $this->_context);
        if (!$this->_mainSocket) {
            throw new Exception($errmsg);
        }

        // Try to open keepalive for tcp and disable Nagle algorithm.
        if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
            $socket = socket_import_stream($this->_mainSocket);
            @socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
            @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
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

        // Register shutdown function for checking errors.
        register_shutdown_function(array("\\Workerman\\Worker", 'checkErrors'));

        // Set autoload root path.
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Create a global event loop.
        if (!self::$globalEvent) {
            $eventLoopClass    = "\\Workerman\\Events\\" . ucfirst(self::getEventLoopName());
            self::$globalEvent = new $eventLoopClass;
            // Register a listener to be notified when server socket is ready to read.
            if ($this->_socketName) {
                self::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
            }
        }

        // Reinstall signal.
        self::reinstallSignal();

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