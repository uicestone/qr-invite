<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of the routes that are handled
| by your application. Just tell Laravel the URIs it should respond
| to using a Closure or controller method. Build something great!
|
*/

// 一些可以在App\Config模型中配置的动态跳转
$router->get('redirect/{slug}', 'WelcomeController@redirect');
// 一个空的测试路由
$router->get('test', 'TestController@index');
