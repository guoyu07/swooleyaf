<?php
/**
 * Created by PhpStorm.
 * User: 姜伟
 * Date: 2017/8/19 0019
 * Time: 8:32
 */
namespace SyServer;

use Constant\ErrorCode;
use Constant\Server;
use DesignPatterns\Singletons\MysqlSingleton;
use DesignPatterns\Singletons\RedisSingleton;
use Log\Log;
use MessageQueue\Producer\RedisProducer;
use Response\Result;
use Tool\Dir;
use Tool\SyPack;
use Tool\Tool;
use Yaf\Application;

abstract class BaseServer {
    /**
     * 请求服务对象
     * @var \swoole_websocket_server|\swoole_server
     */
    protected $_server = null;
    /**
     * 配置数组
     * @var array
     */
    protected $_configs = [];
    /**
     * 请求域名
     * @var string
     */
    protected $_host = '';
    /**
     * 请求端口
     * @var int
     */
    protected $_port = 0;
    /**
     * @var \Yaf\Application
     */
    protected $_app = null;
    /**
     * task进程数量
     * @var int
     */
    protected $_taskNum = 0;
    /**
     * 最大task进程ID号
     * @var int
     */
    protected $_taskMaxId = -1;
    /**
     * pid文件
     * @var string
     */
    protected $_pidFile = '';
    /**
     * @var \Tool\SyPack
     */
    protected $_syPack = null;
    /**
     * 请求ID
     * @var string
     */
    protected static $_reqId = '';
    /**
     * 请求开始毫秒级时间戳
     * @var float
     */
    protected static $_reqStartTime = 0.0;
    /**
     * 服务token码,用于标识不同的服务,每个服务的token不一样
     * @var string
     */
    protected static $_serverToken = '';
    /**
     * 服务配置信息表
     * @var \swoole_table
     */
    protected static $_syServer = null;
    /**
     * 注册的服务信息表
     * @var \swoole_table
     */
    protected static $_syServices = null;
    /**
     * 健康检查列表
     * @var \swoole_table
     */
    protected static $_syHealths = null;
    /**
     * 用户信息列表
     * @var \swoole_table
     */
    protected static $_syUsers = null;
    /**
     * 项目缓存列表
     * @var \swoole_table
     */
    protected static $_syProject = null;
    /**
     * 项目微信商户号token缓存列表
     * @var \swoole_table
     */
    protected static $_syWxShopToken = null;
    /**
     * 项目微信开放平台授权公众号token缓存列表
     * @var \swoole_table
     */
    protected static $_syWxOpenAuthorizerToken = null;
    /**
     * 最大用户数量
     * @var int
     */
    private static $_syUserMaxNum = 0;
    /**
     * 当前用户数量
     * @var int
     */
    private static $_syUserNowNum = 0;
    /**
     * 最大微信商户号token数量
     * @var int
     */
    private static $_syWxShopTokenMaxNum = 0;
    /**
     * 当前微信商户号token数量
     * @var int
     */
    private static $_syWxShopTokenNowNum = 0;
    /**
     * 最大微信开放平台授权公众号token数量
     * @var int
     */
    private static $_syWxOpenAuthorizerTokenMaxNum = 0;
    /**
     * 当前微信开放平台授权公众号token数量
     * @var int
     */
    private static $_syWxOpenAuthorizerTokenNowNum = 0;

    /**
     * BaseServer constructor.
     * @param int $port 端口
     */
    public function __construct(int $port) {
        $os = php_uname('s');
        if(version_compare(PHP_VERSION, Server::VERSION_PHP_MIN, '<')){
            exit('PHP版本必须大于' . Server::VERSION_PHP_MIN . PHP_EOL);
        } else if (!defined('SY_MODULE')) {
            exit('模块名称未定义' . PHP_EOL);
        } else if (($port <= 1000) || ($port > 65535)) {
            exit('端口不合法' . PHP_EOL);
        } else if(!in_array(SY_ENV, Server::$totalEnvProject)){
            exit('环境类型不合法' . PHP_EOL);
        } else if(!in_array($os, Server::$totalEnvSystem)){
            exit('操作系统不支持' . PHP_EOL);
        }

        //检查必要的扩展是否存在
        $missList = array_diff([
            'yac',
            'yaf',
            'PDO',
            'pcre',
            'pcntl',
            'redis',
            'yaconf',
            'swoole',
            'SeasLog',
            'msgpack',
        ], get_loaded_extensions());
        if (!empty($missList)) {
            exit('扩展' . implode(',', $missList) . '不存在' . PHP_EOL);
        }

        $this->_configs = Tool::getConfig('syserver.' . SY_ENV . SY_MODULE);
        self::$_syUserNowNum = 0;
        self::$_syUserMaxNum = (int)$this->_configs['server']['cachenum']['users'];
        if (self::$_syUserMaxNum < 2) {
            exit('用户信息缓存数量不能小于2');
        } else if ((self::$_syUserMaxNum & (self::$_syUserMaxNum - 1)) != 0) {
            exit('用户信息缓存数量必须是2的指数倍');
        }

        define('SY_SERVER_IP', $this->_configs['server']['host']);

        //检测redis服务是否启动
        RedisSingleton::getInstance()->checkConn();

        $this->_configs['server']['port'] = $port;
        //开启TCP快速握手特性,可以提升TCP短连接的响应速度
        $this->_configs['swoole']['tcp_fastopen'] = true;
        //启用异步安全重启特性,Worker进程会等待异步事件完成后再退出
        $this->_configs['swoole']['reload_async'] = true;
        //进程最大等待时间,单位为秒
        $this->_configs['swoole']['max_wait_time'] = 60;
        //设置每次请求发送最大数据包尺寸,单位为字节
        $this->_configs['swoole']['socket_buffer_size'] = Server::SERVER_PACKAGE_MAX_LENGTH;
        $this->_configs['swoole']['buffer_output_size'] = Server::SERVER_OUTPUT_MAX_LENGTH;
        $taskNum = isset($this->_configs['swoole']['task_worker_num']) ? (int)$this->_configs['swoole']['task_worker_num'] : 0;
        if($taskNum < 2){
            exit('Task进程的数量必须大于等于2' . PHP_EOL);
        }
        $this->_taskNum = $taskNum;
        $this->_taskMaxId = $taskNum - 1;
        $this->_host = $this->_configs['server']['host'];
        $this->_port = $this->_configs['server']['port'];
        $this->_pidFile = SY_ROOT . '/pidfile/' . SY_MODULE . $this->_port . '.pid';
        $this->_syPack = new SyPack();

        //生成服务唯一标识
        self::$_serverToken = hash('crc32b', $this->_configs['server']['host'] . ':' . $this->_configs['server']['port']);

        //设置日志目录
        Log::setPath(SY_LOG_PATH);
    }

    private function __clone() {
    }

    protected function initStartBase() {
        register_shutdown_function('\SyError\ErrorHandler::handleFatalError');

        //创建共享内存数据表
        self::$_syServer = new \swoole_table(1);
        self::$_syServer->column('memory_usage', \swoole_table::TYPE_INT, 4);
        self::$_syServer->column('request_times', \swoole_table::TYPE_INT, 4);
        self::$_syServer->column('host_local', \swoole_table::TYPE_STRING, 20);
        self::$_syServer->column('storepath_image', \swoole_table::TYPE_STRING, 150);
        self::$_syServer->column('storepath_music', \swoole_table::TYPE_STRING, 150);
        self::$_syServer->column('storepath_resources', \swoole_table::TYPE_STRING, 150);
        self::$_syServer->column('storepath_cache', \swoole_table::TYPE_STRING, 150);
        self::$_syServer->column('cookiedomain_base', \swoole_table::TYPE_STRING, 60);
        self::$_syServer->create();

        self::$_syServices = new \swoole_table((int)$this->_configs['server']['cachenum']['modules']);
        self::$_syServices->column('module', \swoole_table::TYPE_STRING, 30);
        self::$_syServices->column('host', \swoole_table::TYPE_STRING, 128);
        self::$_syServices->column('port', \swoole_table::TYPE_STRING, 5);
        self::$_syServices->column('type', \swoole_table::TYPE_STRING, 16);
        self::$_syServices->create();

        self::$_syHealths = new \swoole_table((int)$this->_configs['server']['cachenum']['hc']);
        self::$_syHealths->column('tag', \swoole_table::TYPE_STRING, 60);
        self::$_syHealths->column('module', \swoole_table::TYPE_STRING, 30);
        self::$_syHealths->column('uri', \swoole_table::TYPE_STRING, 200);
        self::$_syHealths->create();

        self::$_syUsers = new \swoole_table(self::$_syUserMaxNum);
        self::$_syUsers->column('session_id', \swoole_table::TYPE_STRING, 16);
        self::$_syUsers->column('user_id', \swoole_table::TYPE_STRING, 32);
        self::$_syUsers->column('user_name', \swoole_table::TYPE_STRING, 64);
        self::$_syUsers->column('user_headimage', \swoole_table::TYPE_STRING, 255);
        self::$_syUsers->column('user_openid', \swoole_table::TYPE_STRING, 32);
        self::$_syUsers->column('user_unid', \swoole_table::TYPE_STRING, 32);
        self::$_syUsers->column('user_phone', \swoole_table::TYPE_STRING, 11);
        self::$_syUsers->column('add_time', \swoole_table::TYPE_INT, 4);
        self::$_syUsers->create();

        self::$_syProject = new \swoole_table((int)$this->_configs['server']['cachenum']['local']);
        self::$_syProject->column('tag', \swoole_table::TYPE_STRING, 64);
        self::$_syProject->column('value', \swoole_table::TYPE_STRING, 200);
        self::$_syProject->column('expire_time', \swoole_table::TYPE_INT, 4);
        self::$_syProject->create();

        self::$_syWxShopToken = new \swoole_table((int)$this->_configs['server']['cachenum']['wxshop']['token']);
        self::$_syWxShopToken->column('app_id', \swoole_table::TYPE_STRING, 18);
        self::$_syWxShopToken->column('access_token', \swoole_table::TYPE_STRING, 200);
        self::$_syWxShopToken->column('js_ticket', \swoole_table::TYPE_STRING, 200);
        self::$_syWxShopToken->column('expire_time', \swoole_table::TYPE_INT, 4);
        self::$_syWxShopToken->column('clear_time', \swoole_table::TYPE_INT, 4);
        self::$_syWxShopToken->create();

        self::$_syWxOpenAuthorizerToken = new \swoole_table((int)$this->_configs['server']['cachenum']['wxopen']['authorizertoken']);
        self::$_syWxOpenAuthorizerToken->column('app_id', \swoole_table::TYPE_STRING, 18);
        self::$_syWxOpenAuthorizerToken->column('access_token', \swoole_table::TYPE_STRING, 200);
        self::$_syWxOpenAuthorizerToken->column('js_ticket', \swoole_table::TYPE_STRING, 200);
        self::$_syWxOpenAuthorizerToken->column('expire_time', \swoole_table::TYPE_INT, 4);
        self::$_syWxOpenAuthorizerToken->column('clear_time', \swoole_table::TYPE_INT, 4);
        self::$_syWxOpenAuthorizerToken->create();
    }

    /**
     * 创建请求ID
     */
    protected function createReqId() {
        self::$_reqId = hash('md4', time() . Tool::createNonceStr(4));
    }

    /**
     * @return string
     */
    public static function getReqId() : string {
        return self::$_reqId;
    }

    /**
     * 记录耗时长的请求
     * @param string $uri 请求uri
     * @param string|array $data 请求数据
     */
    protected function reportLongTimeReq(string $uri, $data) {
        $handleTime = (int)((microtime(true) - self::$_reqStartTime) * 1000);
        self::$_reqStartTime = 0;
        if($handleTime > Server::SERVER_TIME_REQ_HANDLE_MAX){ //执行时间超过120毫秒的请求记录到日志便于分析具体情况
            $content = 'handle req use time ' . $handleTime . ' ms,uri:' . $uri . ',data:';
            if(is_string($data)){
                $content .= $data;
            } else {
                $content .= Tool::jsonEncode($data, JSON_UNESCAPED_UNICODE);
            }

            Log::warn($content);
        }
    }

    /**
     * 生成检查标识
     * @return string
     */
    protected function createCheckTag() : string {
        return self::$_serverToken . time() . Tool::createNonceStr(6);
    }

    /**
     * 发送请求健康检查任务
     * @param string $uri
     * @return string
     */
    protected function sendReqHealthCheckTask(string $uri) : string {
        $tag = $this->createCheckTag();
        self::$_syHealths->set($tag, [
            'tag' => $tag,
            'module' => SY_MODULE,
            'uri' => $uri,
        ]);
        $this->_server->after(Server::SERVER_TIME_REQ_HEALTH_MIN, function () use ($tag) {
            $checkData = self::$_syHealths->get($tag);
            if($checkData !== false){
                self::$_syHealths->del($tag);
                RedisProducer::getInstance()->addTopicData(Server::MESSAGE_QUEUE_TOPIC_REDIS_REQ_HEALTH_CHECK, $checkData);
            }
        });

        return $tag;
    }

    /**
     * 开启服务
     */
    abstract public function start();

    /**
     * 帮助信息
     */
    public function help(){
        print_r('帮助信息' . PHP_EOL);
        print_r('-s 操作类型: restart-重启 stop-关闭 start-启动 kz-清理僵尸进程' . PHP_EOL);
        print_r('-n 项目名' . PHP_EOL);
        print_r('-module 模块名' . PHP_EOL);
        print_r('-port 端口,取值范围为1001-65535' . PHP_EOL);
    }

    /**
     * 关闭服务
     */
    public function stop(){
        $str = 'echo -e "\e[1;36m stop ' . SY_MODULE . ': \e[0m';
        if(is_file($this->_pidFile) && is_readable($this->_pidFile)){
            $pid = file_get_contents($this->_pidFile);
        } else {
            $pid = '';
        }
        if($pid){
            \swoole_process::kill($pid);
            file_put_contents($this->_pidFile, '');

            $str .= ' \e[1;32m \t[success] \e[0m';
        } else {
            $str .= ' \e[1;31m \t[fail] \e[0m';
        }
        $str .= '"';
        system($str);

        exit();
    }

    /**
     * 清理僵尸进程
     */
    public function killZombies(){
        $ids = [];
        $commandFind = 'ps -A -o pid,ppid,stat,cmd|grep ' . SY_MODULE . '|awk \'{if(($3 == "Z") || ($3 == "z")) print $1}\'';
        exec($commandFind, $ids);
        if(!empty($ids)){
            $commandKill = 'kill -9 ' . implode(' ', $ids);
            system($commandKill);
        }

        $commandTip = 'echo -e "\e[1;36m kill ' . SY_MODULE . ' zombies: \e[0m \e[1;32m \t[success] \e[0m"';
        system($commandTip);
    }

    /**
     * 通过模块名称获取注册的服务信息
     * @param string $moduleName
     * @return array
     */
    public static function getServiceInfo(string $moduleName) {
        $serviceInfo = self::$_syServices->get($moduleName);
        return $serviceInfo === false ? [] : $serviceInfo;
    }

    /**
     * 获取服务配置信息
     * @param string $field 配置字段名称
     * @param null $default
     * @return mixed
     */
    public static function getServerConfig(string $field=null, $default=null) {
        if (is_null($field)) {
            $data = self::$_syServer->get(self::$_serverToken);
            return $data === false ? [] : $data;
        } else {
            $data = self::$_syServer->get(self::$_serverToken, $field);
            return $data === false ? $default : $data;
        }
    }

    /**
     * 添加本地用户信息
     * @param string $sessionId 会话ID
     * @param array $userData
     * @return bool
     */
    public static function addLocalUserInfo(string $sessionId,array $userData) : bool {
        if (self::$_syUsers->exist($sessionId)) {
            $userData['session_id'] = $sessionId;
            $userData['add_time'] = time();
            self::$_syUsers->set($sessionId, $userData);
            return true;
        } else if (self::$_syUserNowNum < self::$_syUserMaxNum) {
            $userData['session_id'] = $sessionId;
            $userData['add_time'] = time();
            self::$_syUsers->set($sessionId, $userData);
            self::$_syUserNowNum++;
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取本地用户信息
     * @param string $sessionId 会话ID
     * @return array
     */
    public static function getLocalUserInfo(string $sessionId){
        $data = self::$_syUsers->get($sessionId);
        return $data === false ? [] : $data;
    }

    /**
     * 清理本地用户信息缓存
     */
    protected function clearLocalUsers() {
        $time = time() - Server::TIME_EXPIRE_LOCAL_USER_CACHE;
        $delKeys = [];
        foreach (self::$_syUsers as $eUser) {
            if($eUser['add_time'] <= $time){
                $delKeys[] = $eUser['session_id'];
            }
        }
        foreach ($delKeys as $eKey) {
            self::$_syUsers->del($eKey);
        }
        self::$_syUserNowNum = count(self::$_syUsers);
    }

    /**
     * 获取项目缓存
     * @param string $key 键名
     * @param string $field 字段名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getProjectCache(string $key,string $field='', $default=null){
        $data = self::$_syProject->get($key);
        if($data === false){
            return $default;
        } else if($field === ''){
            return $data;
        } else {
            return $data[$field] ?? $default;
        }
    }

    /**
     * 设置项目缓存
     * @param string $key 键名
     * @param array $data 键值
     * @return bool
     */
    public static function setProjectCache(string $key,array $data) : bool {
        $trueKey = trim($key);
        if(strlen($trueKey) > 0){
            $data['tag'] = $trueKey;
            self::$_syProject->set($trueKey, $data);

            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取项目微信商户号token缓存
     * @param string $appId 公众号app id
     * @param string $field 字段名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getWxShopTokenCache(string $appId,string $field='', $default=null){
        $data = self::$_syProject->get($appId);
        if($data === false){
            return $default;
        } else if($field === ''){
            return $data;
        } else {
            return $data[$field] ?? $default;
        }
    }

    /**
     * 设置项目微信商户号token缓存
     * @param string $appId 公众号app id
     * @param array $data 键值
     * @return bool
     */
    public static function setWxShopTokenCache(string $appId,array $data) : bool {
        if(empty($data)){
            return false;
        } else if(self::$_syWxShopToken->exist($appId)){
            self::$_syWxShopToken->set($appId, $data);

            return true;
        } else if(self::$_syWxShopTokenNowNum < self::$_syWxShopTokenMaxNum){
            self::$_syWxShopToken->set($appId, $data);
            self::$_syWxShopTokenNowNum++;

            return true;
        } else {
            return true;
        }
    }

    protected function clearLocalWxShopTokens() {
        $nowTime = time();
        $delKeys = [];
        foreach (self::$_syWxShopToken as $eToken) {
            if($eToken['clear_time'] < $nowTime){
                $delKeys[] = $eToken['app_id'];
            }
        }
        foreach ($delKeys as $eKey) {
            self::$_syWxShopToken->del($eKey);
        }
        self::$_syWxShopTokenNowNum = count(self::$_syWxShopToken);
    }

    /**
     * 获取项目微信开放平台授权公众号token缓存
     * @param string $appId 公众号app id
     * @param string $field 字段名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getWxOpenAuthorizerTokenCache(string $appId,string $field='', $default=null){
        $data = self::$_syProject->get($appId);
        if($data === false){
            return $default;
        } else if($field === ''){
            return $data;
        } else {
            return $data[$field] ?? $default;
        }
    }

    /**
     * 设置项目微信开放平台授权公众号token缓存
     * @param string $appId 公众号app id
     * @param array $data 键值
     * @return bool
     */
    public static function setWxOpenAuthorizerTokenCache(string $appId,array $data) : bool {
        if(empty($data)){
            return false;
        } else if(self::$_syWxOpenAuthorizerToken->exist($appId)){
            self::$_syWxOpenAuthorizerToken->set($appId, $data);

            return true;
        } else if(self::$_syWxOpenAuthorizerTokenNowNum < self::$_syWxOpenAuthorizerTokenMaxNum){
            self::$_syWxOpenAuthorizerToken->set($appId, $data);
            self::$_syWxOpenAuthorizerTokenNowNum++;

            return true;
        } else {
            return true;
        }
    }

    protected function clearLocalWxOpenAuthorizerTokens() {
        $nowTime = time();
        $delKeys = [];
        foreach (self::$_syWxOpenAuthorizerToken as $eToken) {
            if($eToken['clear_time'] < $nowTime){
                $delKeys[] = $eToken['app_id'];
            }
        }
        foreach ($delKeys as $eKey) {
            self::$_syWxOpenAuthorizerToken->del($eKey);
        }
        self::$_syWxOpenAuthorizerTokenNowNum = count(self::$_syWxOpenAuthorizerToken);
    }

    protected function basicWorkStart(\swoole_server $server, $workerId){
        //设置错误和异常处理
        set_exception_handler('\SyError\ErrorHandler::handleException');
        set_error_handler('\SyError\ErrorHandler::handleError');
        //设置时区
        date_default_timezone_set('PRC');

        $this->_app = new Application(APP_PATH . '/conf/application.ini', SY_ENV);
        $this->_app->bootstrap()->getDispatcher()->returnResponse(true);
        $this->_app->bootstrap()->getDispatcher()->autoRender(false);

        if($workerId >= $server->setting['worker_num']){
            @cli_set_process_title(SY_MODULE . $this->_port . 'Task');
        } else {
            @cli_set_process_title(SY_MODULE . $this->_port . 'Worker');
        }

        if($workerId == 0){ //保证每一个服务只执行一次定时任务
            $modules = Tool::getConfig('project.' . SY_ENV . SY_PROJECT . '.modules');
            foreach (Server::$totalModuleBase as $eModuleName) {
                $moduleData = Tool::getArrayVal($modules, $eModuleName, []);
                if (!empty($moduleData)) {
                    self::$_syServices->set($eModuleName, [
                        'module' => $eModuleName,
                        'host' => $moduleData['host'],
                        'port' => (string)$moduleData['port'],
                        'type' => $moduleData['type'],
                    ]);
                }
            }
        }
    }

    protected function basicWorkStop(\swoole_server $server,int $workId) {
        $errCode = $server->getLastError();
        if($errCode > 0){
            Log::error('swoole work stop,workId=' . $workId . ',errorCode=' . $errCode . ',errorMsg=' . print_r(error_get_last(), true));
        }
    }

    protected function basicWorkError(\swoole_server $server, $workId, $workPid, $exitCode){
        if($exitCode > 0){
            $msg = 'swoole work error. work_id=' . $workId . '|work_pid=' . $workPid . '|exit_code=' . $exitCode . '|err_msg=' . $server->getLastError();
            Log::error($msg);
        }
    }

    /**
     * 启动主进程服务
     * @param \swoole_server $server
     */
    public function onStart(\swoole_server $server) {
        @cli_set_process_title(SY_MODULE . $this->_port . 'Main');

        if (file_put_contents($this->_pidFile, $server->master_pid) === false) {
            Log::error('write ' . SY_MODULE . ' pid file error');
        }

        $config = Tool::getConfig('project.' . SY_ENV . SY_PROJECT);
        Dir::create($config['dir']['store']['image']);
        Dir::create($config['dir']['store']['music']);
        Dir::create($config['dir']['store']['resources']);
        Dir::create($config['dir']['store']['cache']);
        self::$_syServer->set(self::$_serverToken, [
            'memory_usage' => memory_get_usage(),
            'request_times' => 0,
            'host_local' => $this->_host,
            'storepath_image' => $config['dir']['store']['image'],
            'storepath_music' => $config['dir']['store']['music'],
            'storepath_resources' => $config['dir']['store']['resources'],
            'storepath_cache' => $config['dir']['store']['cache'],
            'cookiedomain_base' => $config['domain']['cookie']['base'],
        ]);
    }

    /**
     * 启动管理进程
     * @param \swoole_server $server
     */
    public function onManagerStart(\swoole_server $server){
        @cli_set_process_title(SY_MODULE . $this->_port . 'Manager');
    }

    /**
     * 关闭服务
     * @param \swoole_server $server
     */
    public function onShutdown(\swoole_server $server){
    }

    /**
     * 任务完成
     * @param \swoole_server $server
     * @param int $taskId
     * @param string $data
     */
    public function onFinish(\swoole_server $server, $taskId,string $data){
        $dataArr = Tool::jsonDecode($data);
        if ((!is_array($dataArr)) || ($dataArr['code'] > 0)) {
            Log::info('handle task fail with msg:' . $data);
        }
    }

    /**
     * 关闭连接
     * @param \swoole_server $server
     * @param int $fd 连接的文件描述符
     * @param int $reactorId reactor线程ID,$reactorId<0:服务器端关闭 $reactorId>0:客户端关闭
     */
    public function onClose(\swoole_server $server,int $fd,int $reactorId) {
    }

    /**
     * 处理服务端命令
     * @param string $command
     * @param array $data
     * @return bool
     */
    private function handleTaskServer(string $command,array $data) {
        $result = true;

        Log::error('task command not exist handle');
        $result = false;

        return $result;
    }

    protected function handleTaskBase(\swoole_server $server,int $taskId,int $fromId,string $data){
        $result = new Result();
        if(!$this->_syPack->unpackData($data)){
            $result->setCodeMsg(ErrorCode::COMMON_PARAM_ERROR, '数据格式不合法');
            return $result->getJson();
        }

        RedisSingleton::getInstance()->reConnect();
        MysqlSingleton::getInstance()->reConnect();

        $command = $this->_syPack->getCommand();
        $commandData = $this->_syPack->getData();
        $this->_syPack->init();

        if(in_array($command, [SyPack::COMMAND_TYPE_SOCKET_CLIENT_SEND_TASK_REQ, SyPack::COMMAND_TYPE_RPC_CLIENT_SEND_TASK_REQ])){
            return [
                'command' => $command,
                'params' => $commandData,
            ];
        }

        if($this->handleTaskServer($command, $commandData)){
            $result->setData([
                'result' => 'success',
            ]);
        } else {
            $result->setData([
                'result' => 'fail',
            ]);
        }

        return $result->getJson();
    }

    /**
     * 启动工作进程
     * @param \swoole_server $server
     * @param int $workerId 进程编号
     */
    abstract public function onWorkerStart(\swoole_server $server, $workerId);
    /**
     * 退出工作进程
     * @param \swoole_server $server
     * @param int $workerId
     * @return mixed
     */
    abstract public function onWorkerStop(\swoole_server $server, int $workerId);
    /**
     * 工作进程错误处理
     * @param \swoole_server $server
     * @param int $workId 进程编号
     * @param int $workPid 进程ID
     * @param int $exitCode 退出状态码
     */
    abstract public function onWorkerError(\swoole_server $server, $workId, $workPid, $exitCode);
    /**
     * 处理任务
     * @param \swoole_server $server
     * @param int $taskId
     * @param int $fromId
     * @param string $data
     * @return string
     */
    abstract public function onTask(\swoole_server $server,int $taskId,int $fromId,string $data);
}