<?php
use \Workerman\Worker;
use \Workerman\WebServer;

require_once __DIR__ . '/Saltyguy/Autoloader.php';

// 创建一个Worker监听2345端口，使用http协议通讯
$webserver = new WebServer("http://0.0.0.0:2344");
$webserver->addRoot('www.example.com', '/home/lw/Saltyguy/');
// 启动4个进程对外提供服务
$webserver->count = 4;



// 运行worker
Worker::runAll();
