<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

$router->get('order/capacity', 'OrderController@getCapacity');
// 内容相关
$router->get('/', 'WelcomeController@home');
$router->resource('user', 'UserController');
$router->resource('post', 'PostController');
$router->post('post/{post}', 'PostController@restore'); // 恢复软删除的内容
$router->get('post/{post}/content', 'PostController@display'); // 直接以HTML显示内容
$router->resource('tag', 'TagController');
$router->resource('message', 'MessageController');
$router->put('message', 'MessageController@updateMultiple'); // 批量更新消息, 用于标记已读
$router->resource('config', 'ConfigController');
$router->resource('task', 'TaskController');
$router->resource('order', 'OrderController');

// 用户相关
$router->post('auth/login', 'UserController@authenticate'); // 登陆
$router->get('auth/user', 'UserController@getAuthenticatedUser'); // 获得当前登录的用户
$router->put('auth/user', 'UserController@updateAuthenticatedUser'); // 更新当前登录的用户
$router->get('code/{mobile}', 'UserController@verifyMobile'); // 获得手机验证码
$router->post('code/{mobile}', 'UserController@verifyMobile'); // 验证手机验证码

// 微信相关
$router->get('wx/account/{name?}', 'WeixinController@getAccount'); // 获得当前域名对应的公众号, 及其JSApi初始化数据
$router->get('wx/account-list', 'WeixinController@getAccountList'); // 公众号列表
$router->get('wx/template-list/{name?}', 'WeixinController@getTemplateList'); // 可用模板消息列表
$router->get('wx/{account}/{code}/{scope?}', 'WeixinController@oAuth'); // 网页/App授权登录
$router->any('wx/payment-confirm/{account}', ['as'=>'weixin_payment_confirm', 'uses'=>'WeixinController@paymentConfirm']); // 支付回调响应
$router->any('wx/{account}', ['as'=>'weixin_serve', 'uses'=>'WeixinController@serve']); // 接受微信消息事件推送的页面

// 系统, 后台相关
$router->get('dashboard/{item}', 'DashboardController@index'); // 数据统计
$router->get('qiniu/token', 'QiniuController@getToken'); // 获得七牛Token
$router->get('ip-location', 'UserController@ipLocation'); // 根据IP获得地区名
$router->post('error', 'ErrorController@log'); // 提交客户端错误
$router->post('webhook', 'WebhookController@index');
$router->get('version', 'WelcomeController@version');

$router->get('redirect-answer/{id}', 'WelcomeController@redirectAnswer');
