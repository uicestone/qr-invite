var secrure		= process.argv[3] && process.argv[3] !== '--insecure';

if (secrure) {
	var fs = require('fs');
	var ca = fs.readFileSync('/etc/apache2/ssl/sub.class1.server.ca.pem');
	var privateKey = fs.readFileSync('/etc/apache2/ssl/qr-invite.key', 'utf8');
	var certificate = fs.readFileSync('/etc/apache2/ssl/qr-invite.crt', 'utf8');
	var credentials = {ca: ca, key: privateKey, cert: certificate};
}

var app = secrure ? require('https').createServer(credentials, handler) : require('http').createServer(handler);

var io = require('socket.io')(app);

var Redis = require('ioredis');
var redis = new Redis();

// Listen WebSocket port
app.listen(6001, function () {
	console.log('Server is running!');
});

// Return empty 200 OK to all request
function handler(req, res) {
	res.writeHead(200);
	res.end('');
}

io.on('connection', function (socket) {

	var clientData = [
		socket.conn.remoteAddress,
		socket.client.id,
		socket.handshake.headers['user-agent']
	];

	console.log('[' + new Date() + '] Client connected. ' + clientData.join(' ') + ' (total connections: ' + io.eio.clientsCount + ')');

	socket.on('disconnect', function () {
		socket.disconnect();
		console.log('[' + new Date() + '] Client disconnected. ' + clientData.join(' ') + ' (total connections: ' + io.eio.clientsCount + ')');
	});

	socket.on('subscribe', function (channel) {
		socket.join(channel);
		console.log('[' + new Date() + '] Client subscribed channel ' + channel + '. ' + clientData.join(' '));
	});

	socket.on('unsubscribe', function (channel) {
		socket.leave(channel);
		console.log('[' + new Date() + '] Client unsubscribed channel ' + channel + '. ' + clientData.join(' '));
	});
	
});

redis.psubscribe('*', function (err, count) {
	if (err) {
		console.error(err);
		return;
	}
	console.log('[' + new Date() + '] PRedis connected.');
});

// When there's a PRedis message, broadcast to subscribers
redis.on('pmessage', function (subscribed, channel, message) {
	// console.log(message);
	message = JSON.parse(message);
	io.sockets.in(channel).emit('message', message.data);
});
