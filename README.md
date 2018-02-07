# 环境要求
## 基础环境
- PHP7+
- redis3.2+
- etcd3
- inotify

## 必要扩展
- swoole1.9.21+
- msgpack
- yaf3.0.4+
- yaconf1.0+
- yac2.0+
- redis3.0+
- Seaslog1.6+
- PDO
- pcre
- pcntl
- opcache

## 可选扩展
- imgick3.4+
- mongodb1.2+
- xdebug2.5+
- xhprof1.0+

## 其他
- gcc4.8+ //php7编译用gcc4.8+会开启Global Register for opline and execute_data支持, 这个会带来5%左右的性能提升

# 框架介绍
## 使用介绍
- 操作系统只支持linux,不支持windows,因为pcntl扩展,nohup,inotify只有linux才可用
- nginx建议使用版本大于1.9,因为1.9的nginx增加了stream模块,支持tcp反向代理和负载均衡
- favicon.ico请求不在框架内部做处理,建议配置nginx静态文件访问来实现获取该文件
- 建议单独设置一个文件服务模块用于处理文件上传,图片裁剪等功能
- 多服务器部署,必须确保服务端口对外开放,以避免服务模块跨服务器请求调用因端口未开放出现错误
- 框架内部模块之间请求调用全都不用cookie和session
- task任务投递不要投递到taskId=0的进程,该进程用于定时更新模块配置信息
- 对外部只开放api模块,需要获取其他模块的数据,通过发送rpc请求到其他模块获取数据
- api模块返回数据根据业务需求,既可以用控制器的SyResult对象,也可以直接在响应请求中直接设置数据
- api模块负责接受外部请求,返回响应数据,包括设置响应头,cookie等
- 非api模块返回数据统一用控制器的SyResult对象
- 非api模块不能设置响应头,cookie等信息,如需设置这些信息,将这些信息作为响应数据放到SyResult中,返回给api组装来间接设置响应头,cookie等
- 非api模块发送请求只有POST方式,不支持其他方式
- 图片上传请参考api模块Image控制器的uploadImageAction方法
- 微信,支付宝支付与回调处理请参考sy_order模块下的OrderDao文件
- 所有数据库表必须有且只能有单主键,不允许联合主键
- 拉取项目需要安装git和git-lfs,有部分文件是git-lfs上传

## 目录介绍
- syLibs: 公共类目录
- pidfile: 项目进程pid文件存放目录
- static: 静态文件目录
- yaconf: 框架配置文件目录,该目录内的配置文件为样例,使用时需要将配置文件移动到php.ini配置文件中yaconf.directory配置对应的目录下
- install: 框架工具目录,存放了环境搭建需要的工具
- 其他目录: 项目模块目录,每一个目录对应一个项目模块

## 命令
```
    //前置命令,必须在开启服务之前运行
    nohup etcd --listen-client-urls http://10.27.166.170:2379 --advertise-client-urls http://10.27.166.170:2379 >/dev/null & --启动etcd服务
    nohup etcdctl watch sydev/modules/ sydev/modules0 >/usr/local/inotify/symodules/change_service.txt --endpoints=[10.27.166.170:2379] 2>&1 & --启动etcd监听服务
    chmod a+x /home/jw/phpspace/swooleyaf/symodules_inotify.sh
    nohup sh /home/jw/phpspace/swooleyaf/symodules_inotify.sh >/dev/null 2>&1 & --启动inotify实时更新
    //服务命令
    /usr/local/php7/bin/php helper_service_manager.php -s start-all --启动服务
    /usr/local/php7/bin/php helper_service_manager.php -s stop-all --关闭服务
    //微信更新access token和js ticket缓存
    //1:必须将helper_sytask.php文件加入到linux系统cron执行任务中
    //2:强制刷新缓存: /usr/local/php7/bin/php helper_sytask.php -refreshwx 1
    //3:如需要用到微信缓存,必须在每次启动服务后执行上述命令
    
    //清理脚本-解决cli模式php内存缓慢泄漏的问题(待验证),建议每隔一段时间执行一次,比如一个小时执行一次
    sync && echo 3 > /proc/sys/vm/drop_caches
```

## 预定义常量
- SY_ROOT //框架根目录
- SY_ENV //框架环境 dev:测试环境 product:生产环境
- SY_PROJECT //框架项目名称
- SY_VERSION //框架版本号
- SY_MODULE //框架模块名称
- SY_API //框架API标识 true: 是对外的API接口 false:不是对外的API接口
- SY_SID_LENGTH //框架服务标识长度

## 服务管理
### 获取框架概览信息
    请求地址: http://api.xxx.com/syinfo
### 获取php信息
    请求地址: http://api.xxx.com/phpinfo
### 关闭或开启服务
    请求地址: http://api.xxx.com/serverctl
    请求参数:
        server_ip: string 服务IP
        server_port: int 服务端口
        server_status: int 服务状态 0:关闭 1:开启
        
## 定时任务
1. 定时任务处理都是通过发送HTTP GET请求的方式进行,在执行定时任务之前,必须确保请求接口可正常访问
2. 目前支持的定时任务有三种: 
- 一次性定时任务,必须指定任务的执行时间戳
- 间隔定时任务,必须指定任务的间隔时间,单位为秒
- cron定时任务,必须指定任务的cron计划时间
### 命令
```
    //添加脚本执行权限
    chmod a+x /home/jw/phpspace/swooleyaf/startTaskCron.sh
    chmod a+x /home/jw/phpspace/swooleyaf/startTaskInterval.sh
    chmod a+x /home/jw/phpspace/swooleyaf/startTaskSingle.sh
    //启动定时任务
    nohup sh /home/jw/phpspace/swooleyaf/startTaskCron.sh 2>&1 >/dev/null &
    nohup sh /home/jw/phpspace/swooleyaf/startTaskInterval.sh 2>&1 >/dev/null &
    nohup sh /home/jw/phpspace/swooleyaf/startTaskSingle.sh 2>&1 >/dev/null &
    //关闭定时任务
    ps -ef | grep startTaskCron.sh |kill -9
    ps -ef | grep startTaskInterval.sh |kill -9
    ps -ef | grep startTaskSingle.sh |kill -9
```