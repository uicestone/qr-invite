# 微信二维码邀请传播和CMS后端API

基于Laravel 5.3，为CMS提供后端API，包含微信二维码邀请传播功能

包含一组服务器端脚本，用以维护数据

# 依赖
- php7.0
- composer
- Node.js & npm
- MySQL5.7
- Redis Server

## 生产环境
- Web Server (Apache/Nginx)
- pm2
- supervisor

# 安装

```
composer install
npm install
```

# 开发环境运行

```
php artisan serve
php artisan queue:work --daemon --tries=1
node socket.io.js
-> http://localhost:8000
```

# API文档
[APIs.md](https://github.com/uicestone/qr-invite/blob/master/APIs.md)
