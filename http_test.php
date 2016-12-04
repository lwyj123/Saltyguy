<?php
use \Workerman\Worker;
use \Workerman\WebServer;

require_once __DIR__ . '/Workerman/Autoloader.php';

// 创建一个Worker监听2345端口，使用http协议通讯
$webserver = new WebServer("http://0.0.0.0:2348");
$webserver->addRoot('www.example.com', '/home/lw/Saltyguy/');
// 启动4个进程对外提供服务
$webserver->count = 4;


// 接收到浏览器发送的数据时回复hello world给浏览器
$webserver->onMessage = function($connection, $data)
{
    // 向浏览器发送hello world
    $connection->send('hello world and fvck java');
};

// 运行worker
Worker::runAll();
