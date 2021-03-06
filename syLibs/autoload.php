<?php
final class SyFrameLoader {
    /**
     * @var \SyFrameLoader
     */
    private static $instance = null;
    /**
     * @var array
     */
    private $preHandleMap = [];
    /**
     * swift mailer未初始化标识 true：未初始化 false：已初始化
     * @var bool
     */
    private $swiftMailerStatus = true;
    /**
     * smarty未初始化标识 true：未初始化 false：已初始化
     * @var bool
     */
    private $smartyStatus = true;
    /**
     * @var array
     */
    private $smartyRootClasses = [];
    /**
     * fpdf未初始化标识 true：未初始化 false：已初始化
     * @var bool
     */
    private $fpdfStatus = true;

    private function __construct() {
        $this->preHandleMap = [
            'FPdf' => 'preHandleFPdf',
            'Twig' => 'preHandleTwig',
            'Swift' => 'preHandleSwift',
            'Resque' => 'preHandleResque',
            'Smarty' => 'preHandleSmarty',
            'SmartyBC' => 'preHandleSmarty',
            'PHPExcel' => 'preHandlePhpExcel',
        ];

        $this->smartyRootClasses = [
            'smarty' => 'smarty.php',
            'smartybc' => 'smartybc.php',
        ];
    }

    private function __clone() {
    }

    /**
     * @return \SyFrameLoader
     */
    public static function getInstance() {
        if(is_null(self::$instance)){
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function preHandleFPdf(string $className) : string {
        if($this->fpdfStatus){
            define('FPDF_VERSION', '1.81');
            $this->fpdfStatus = false;
        }

        return SY_ROOT . '/syLibs/' . $className . '.php';
    }

    private function preHandleTwig(string $className) : string {
        return SY_ROOT . '/syLibs/Template/' . str_replace('_', '/', $className) . '.php';
    }

    private function preHandleSwift(string $className) : string {
        if($this->swiftMailerStatus){ //加载swift mailer依赖文件
            require_once SY_ROOT . '/syLibs/Mailer/Swift/depends/cache_deps.php';
            require_once SY_ROOT . '/syLibs/Mailer/Swift/depends/mime_deps.php';
            require_once SY_ROOT . '/syLibs/Mailer/Swift/depends/message_deps.php';
            require_once SY_ROOT . '/syLibs/Mailer/Swift/depends/transport_deps.php';
            require_once SY_ROOT . '/syLibs/Mailer/Swift/depends/preferences.php';

            $this->swiftMailerStatus = false;
        }

        return SY_ROOT . '/syLibs/Mailer/' . str_replace('_', '/', $className) . '.php';
    }

    private function preHandleResque(string $className) : string {
        return SY_ROOT . '/syLibs/Queue/' . str_replace('_', '/', $className) . '.php';
    }

    private function preHandleSmarty(string $className) : string {
        if ($this->smartyStatus) {
            $smartyLibDir = SY_ROOT . '/syLibs/Template/Smarty/libs/';
            define('SMARTY_DIR', $smartyLibDir);
            define('SMARTY_SYSPLUGINS_DIR', $smartyLibDir . '/sysplugins/');
            define('SMARTY_RESOURCE_CHAR_SET', 'UTF-8');

            $this->smartyStatus = false;
        }

        $lowerClassName = strtolower($className);
        if(isset($this->smartyRootClasses[$lowerClassName])){
            return SMARTY_DIR . $this->smartyRootClasses[$lowerClassName];
        } else {
            return SMARTY_SYSPLUGINS_DIR . $lowerClassName . '.php';
        }
    }

    private function preHandlePhpExcel(string $className) : string {
        return SY_ROOT . '/syLibs/Excel/' . str_replace('_', '/', $className) . '.php';
    }

    /**
     * 加载文件
     * @param string $className 类名
     * @return bool
     */
    public function loadFile(string $className) : bool {
        $nameArr = explode('/', $className);
        $funcName = $this->preHandleMap[$nameArr[0]] ?? null;
        if(is_null($funcName)){
            $nameArr = explode('_', $className);
            $funcName = $this->preHandleMap[$nameArr[0]] ?? null;
        }

        $file = is_null($funcName) ? SY_ROOT . '/syLibs/' . $className . '.php' : $this->$funcName($className);
        if(is_file($file) && is_readable($file)){
            require_once $file;
            return true;
        }

        return false;
    }
}

/**
 * 类自动加载
 * @param string $className 类全名
 * @return bool
 */
function syAutoload(string $className) {
    $trueName = str_replace([
        '\\',
        "\0",
    ], [
        '/',
        '',
    ], $className);
    return SyFrameLoader::getInstance()->loadFile($trueName);
}
spl_autoload_register('syAutoload');