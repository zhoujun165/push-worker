<?php

//namespace Push;

class Worker
{

    public static $pid_file = '';

    public static $log_file = '';

    public static $master_pid = 0;

    public static $stdoutFile = '/dev/null';

    public static $workers = array();

    public static $worker_num = 3;

    public static $worker_names = array('ios', 'jpush', 'alipush');

    public static $worker_name = '';

    public static $status = 0;


    const STATUS_RUNNING = 1;
    const STATUS_SHUTDOWN = 2;

    public static function runAll()
    {
        self::checkEnv();
        self::init();
        self::parseCommand();
        self::daemonize();
        self::installSignal();
        self::saveMasterPid();
        self::forkWorkers();
        self::resetStd();
        self::monitorWorkers();
    }

    protected static function checkEnv()
    {
        if (php_sapi_name() != 'cli') {
            exit('only run in command line mode!');
        }
    }

    protected static function init()
    {

        $temp_dir = sys_get_temp_dir() . '/push_worker';

        if (!is_dir($temp_dir)) {
            @mkdir($temp_dir);
        }

        if (empty(self::$pid_file)) {
            self::$pid_file = $temp_dir . '/worker.pid';
        }

        if (empty(self::$log_file)) {
            self::$log_file = $temp_dir . '/worker.log';
        }
    }

    protected static function parseCommand()
    {

        global $argv;

        if (!isset($argv[1])) {
            exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }
        $command = $argv[1];
        //check master is exist
        $master_id = @file_get_contents(self::$pid_file);
        $master_is_alive = $master_id && posix_kill($master_id, 0);

        if ($master_is_alive) {
            if ($command == 'start' && posix_getpid() != $master_id) {
                exit('push worker is already running!'.PHP_EOL);
            }
        }
        switch ($command) {
            case 'start':
                break;
            case 'status':

                exit(0);
            case 'stop':
                //向主进程发出stop的信号
                self::log('push worker['.$master_id.'] stopping....');
                echo 'push worker['.$master_id.'] stopping....' . PHP_EOL;
                $master_id && $flag = posix_kill($master_id, SIGINT);
                while($master_id && posix_kill($master_id, 0)){
                    usleep(500000);
                }
                self::log('push worker['.$master_id.'] stop success');
                echo 'push worker['.$master_id.'] stop success' . PHP_EOL;
                exit(0);
                break;
            default:
                exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
                break;
        }


    }

    protected static function daemonize()
    {
        umask(0);
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception("fork fail");
        } elseif ($pid > 0) {
            exit(0);
        } else {
            if (-1 === posix_setsid()) {
                throw new Exception("setsid fail");
            }
            self::setProcessTitle('push worker: master');
        }
    }

    protected static function saveMasterPid(){
        self::$master_pid = posix_getpid();
        if(false === @file_put_contents(self::$pid_file, self::$master_pid)){
            throw new Exception('fail to save master pid: ' . self::$master_pid);
        }
    }

    protected static function forkWorkers()
    {
        while (count(self::$workers) < self::$worker_num) {
            $curr_name = current(self::$worker_names);
            if (!in_array($curr_name, array_values(self::$workers))) {
                self::forkOneWorker($curr_name);
                next(self::$worker_names);
            }
        }
    }

    protected static function installSignal()
    {
        pcntl_signal(SIGINT, array('Worker','signalHandler'),false);
    }

    public static function signalHandler($signal)
    {
        switch ($signal) {
            case SIGINT: // Stop.
                self::stopAll();
                break;
            // Reload.
            case SIGUSR1:
                break;
            // Show status.
            case SIGUSR2:
                break;
        }
    }

    protected static function forkOneWorker($worker_name)
    {

        $pid = pcntl_fork();
        if ($pid > 0) {
            self::$workers[$pid] = $worker_name;
        } elseif ($pid == 0) {
            self::$worker_name = $worker_name;
            self::log($worker_name . ' push worker start');
            self::setProcessTitle('push worker: '.$worker_name);
            while (1) {
                pcntl_signal_dispatch();
                sleep(1);
            }
        } else {
            throw new Exception('fork one worker fail');
        }
    }

    protected static function resetStd()
    {
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile, "a");
            $STDERR = fopen(self::$stdoutFile, "a");
        } else {
            throw new Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }

    protected static function monitorWorkers()
    {
        self::$status = self::STATUS_RUNNING;
        while (1) {
            pcntl_signal_dispatch();
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch();
            //child exit
            if ($pid > 0) {
                if(self::$status != self::STATUS_SHUTDOWN){
                    $worker_name = self::$workers[$pid];
                    unset(self::$workers[$pid]);
                    self::forkOneWorker($worker_name);
                }
            }
        }

    }

    protected static function setProcessTitle($title)
    {
        if (function_exists('cli_set_process_title')) {
            if (!cli_set_process_title($title)) {
                self::log('unable set process title');
            }
        }
    }
    
    protected static function stopAll(){
        $pid = posix_getpid();
        if(self::$master_pid == $pid){ //master
            self::$status = self::STATUS_SHUTDOWN;
            foreach (self::$workers as $pid=>$worker_name){
                posix_kill($pid, SIGINT);
                //TODO 使用定时器监控worker关闭情况，当worker全部关闭完成之后，关闭master进程
            }
            @unlink(self::$pid_file);
            exit(0);
        }else{ //child
            //TODO 查看当前是否有任务在执行，执行完毕后才退出
            self::log('push worker ' . self::$worker_name . ' pid: '.$pid.' stop');
            exit(0);
        }
    }

    protected static function log($message)
    {
        $message = date('Y-m-d H:i:s') . ' pid:' . posix_getpid() . ' ' . $message . "\n";
        file_put_contents((string)self::$log_file, $message, FILE_APPEND | LOCK_EX);
    }

}
