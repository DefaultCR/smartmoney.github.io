var fs = require('fs'),
    options = {
        key: fs.readFileSync('/var/www/html/storage/bot/privkey.pem', 'utf8'),
        cert: fs.readFileSync('/var/www/html/storage/bot/fullchain.pem', 'utf8')
    },
    config          = require('./config.js'),
    app             = require('express')(),
    server          = require('https').createServer(options, app),
    io              = require('socket.io')(server),
    crash           = new (require('./crash'))(io),
    roulette        = new (require('./double'))(io, config.domain);

/*crash.start(); // start crash game*/
/*roulette.start(); // start double game*/

io.on('connection', (socket) => {
    socket.on('withdraw', (persona) => {
        crash.withdraw(persona.hash, persona.multiplier, (res) => {
            socket.emit('withdraw', res);
        });
    });
});

var redis = require('redis');
    redis = redis.createClient();

var Redis = require('redis');
    client = Redis.createClient();
var requestify = require('requestify');

function log(log) { console.log('[APP] ' + log) }

server.listen(config.port);
log('Локальный сервер запущен на порте '+config.port);
 
/* USERS ONLINE SITE */
/*setInterval(function() {
    updateOnline();
}, Math.round(getRandomArbitrary(3, 5) * 1e3));
io.sockets.on('connection', function(socket) {
    updateOnline();
    socket.on('disconnect', function(){
        updateOnline();
    })
});

function updateOnline(){
    io.sockets.emit('online', Object.keys(io.sockets.adapter.rooms).length+onlineplus-getRandomInt(1, 4));
}*/

/* USERS ONLINE SITE END */

/* USERS ONLINE SITE */
var count = 0;
var $ipsConnected = [];

io.on('connection', function (socket) {
	var $ipAddress = socket.handshake.address;
	if (!$ipsConnected.hasOwnProperty($ipAddress)) {
		$ipsConnected[$ipAddress] = 1;
		count++;
		socket.emit('online', count);
	}

	socket.on('disconnect', function() {
		if ($ipsConnected.hasOwnProperty($ipAddress)) {
			delete $ipsConnected[$ipAddress];
			count--;
			socket.emit('online', count);
		}
	});
});
/* USERS ONLINE SITE END */

redis.subscribe('chat.clear');
redis.subscribe('new.msg');
redis.subscribe('updateBalance');
redis.subscribe('updateBalanceAfter');
redis.subscribe('message');
redis.subscribe('jackpot_room1.newBet');
redis.subscribe('jackpot_room1.timer');
redis.subscribe('jackpot_room2.newBet');
redis.subscribe('jackpot_room2.timer');
redis.subscribe('jackpot_room3.newBet');
redis.subscribe('jackpot_room3.timer');
/*redis.subscribe('roulette');*/
redis.subscribe('crash');
redis.subscribe('crash.stop');
redis.subscribe('new.flip');
redis.subscribe('end.flip');

redis.on('message', function(channel, message) {
    if (channel == 'chat.clear') {
        log('[CHAT] Чат очищен!');
        io.sockets.emit('clear', message);
    }
    if(channel == 'crash') return crash.emit('crash', JSON.parse(message));
    if(channel == 'crash.stop') return crash.stopAnimate(false);
    if (channel == 'new.msg') {
        io.sockets.emit('chat', message);
    }
    if(channel == 'jackpot_room1.timer') {
        message = JSON.parse(message);
        JackpotStartTimer(message.time);
        return;
    }
    if(channel == 'jackpot_room2.timer') {
        message = JSON.parse(message);
        JackpotStartTimer_room2(message.time);
        return;
    }
    if(channel == 'jackpot_room3.timer') {
        message = JSON.parse(message);
        JackpotStartTimer_room3(message.time);
        return;
    }
    if (channel == 'new.flip') {
        io.sockets.emit(channel, JSON.parse(message));
		return;
    }
    if (channel == 'end.flip') {
        io.sockets.emit(channel, JSON.parse(message));
		return;
    }
    if(channel == 'updateBalanceAfter') setTimeout(function() {
        io.sockets.emit('updateBalance', JSON.parse(message));
    }, 13000);
    /*if(channel == 'roulette' && message.type == 'back_timer') {
        message = JSON.parse(message);
        return double.startTimer(message.timer);
    }*/
    if(typeof message == 'string') return io.sockets.emit(channel, JSON.parse(message));
    io.sockets.emit(channel, message);
});

function JackpotStartTimer(time) {
	JackpotSetStatus(1, 1);
    var timer = setInterval(function() {
        if(time == 0) {
            clearInterval(timer);
            JackpotGetSlider();
            return;
        }
		if(time <= 5) {
            JackpotSetStatus(1, 2);
        }
        time--;
        io.sockets.emit('jackpot_room1.timer', {
            time : time
        });
    }, 1000);
}

function JackpotStartTimer_room2(time) {
	JackpotSetStatus(2, 1);
    var timer = setInterval(function() {
        if(time == 0) {
            clearInterval(timer);
            JackpotGetSlider_room2();
            return;
        }
		if(time <= 5) {
            JackpotSetStatus(2, 2);
        }
        time--;
        io.sockets.emit('jackpot_room2.timer', {
            time : time
        });
    }, 1000);
}

function JackpotStartTimer_room3(time) {
	JackpotSetStatus(3, 1);
    var timer = setInterval(function() {
        if(time == 0) {
            clearInterval(timer);
            JackpotGetSlider_room3();
            return;
        }
		if(time <= 5) {
            JackpotSetStatus(3, 2);
        }
        time--;
        io.sockets.emit('jackpot_room3.timer', {
            time : time
        });
    }, 1000);
}

function JackpotGetSlider() {
    requestify.post(config.domain + '/api/jackpot/getSlider', {
        room : 1
    })
    .then(function(res) {
        res = JSON.parse(res.body);
        io.sockets.emit('jackpot_room1.slider', res);
		JackpotSetStatus(1, 3);
        setTimeout(function() {
            JackpotNewGame();
        }, 10000);
    }, function(res) {
        log('[ROOM #1] Ошибка в функции getSlider');
    });
}

function JackpotGetSlider_room2() {
    requestify.post(config.domain + '/api/jackpot/getSlider', {
        room : 2
    })
    .then(function(res) {
        res = JSON.parse(res.body);
        io.sockets.emit('jackpot_room2.slider', res);
		JackpotSetStatus(2, 3);
        setTimeout(function() {
            JackpotNewGame_room2();
        }, 10000);
    }, function(res) {
        log('[ROOM #2] Ошибка в функции getSlider');
    });
}

function JackpotGetSlider_room3() {
    requestify.post(config.domain + '/api/jackpot/getSlider', {
        room : 3
    })
    .then(function(res) {
        res = JSON.parse(res.body);
        io.sockets.emit('jackpot_room3.slider', res);
		JackpotSetStatus(3, 3);
        setTimeout(function() {
            JackpotNewGame_room3();
        }, 10000);
    }, function(res) {
        log('[ROOM #3] Ошибка в функции getSlider');
    });
}

function JackpotNewGame() {
    requestify.post(config.domain + '/api/jackpot/newGame', {
        room : 1
    })
    .then(function(res) {
        res = JSON.parse(res.body);
        io.sockets.emit('jackpot_room1.newGame', res);
    }, function(res) {
        log('[ROOM #1] Ошибка в функции newGame');
    });
}

function JackpotNewGame_room2() {
    requestify.post(config.domain + '/api/jackpot/newGame', {
        room : 2
    })
    .then(function(res) {
        res = JSON.parse(res.body);
        io.sockets.emit('jackpot_room2.newGame', res);
    }, function(res) {
        log('[ROOM #2] Ошибка в функции newGame');
    });
}

function JackpotNewGame_room3() {
    requestify.post(config.domain + '/api/jackpot/newGame', {
        room : 3
    })
    .then(function(res) {
        res = JSON.parse(res.body);
        io.sockets.emit('jackpot_room3.newGame', res);
    }, function(res) {
        log('[ROOM #3] Ошибка в функции newGame');
    });
}

function JackpotSetStatus(room, status) {
    requestify.post(config.domain + '/api/jackpot/setStatus', {
        room : room,
		status : status
    })
    .then(function(res) {
        res = JSON.parse(res.body);
		log(res.msg);
    }, function(res) {
        log('[ROOM #1] Ошибка в функции setStatus');
    });
}

// Проверка статусов
requestify.post(config.domain + '/api/jackpot/getStatus', {
    room : 1
})
.then(function(res) {
    res = JSON.parse(res.body);
    log('[JACKPOT ROOM #1] Current game #' + res.id)
    if(res.status == 1) JackpotStartTimer(res.time);
    if(res.status == 2) JackpotGetSlider();
    if(res.status == 3) JackpotNewGame();
}, function(res) {
    log('[ROOM #1] Ошибка в функции getStatus');
});

requestify.post(config.domain + '/api/jackpot/getStatus', {
    room : 2
})
.then(function(res) {
    res = JSON.parse(res.body);
    log('[JACKPOT ROOM #2] Current game #' + res.id)
    if(res.status == 1) JackpotStartTimer_room2(res.time);
    if(res.status == 2) JackpotGetSlider_room2();
    if(res.status == 3) JackpotNewGame_room2();
}, function(res) {
    log('[ROOM #2] Ошибка в функции getStatus');
});

requestify.post(config.domain + '/api/jackpot/getStatus', {
    room : 3
})
.then(function(res) {
    res = JSON.parse(res.body);
    log('[JACKPOT ROOM #3] Current game #' + res.id)
    if(res.status == 1) JackpotStartTimer_room3(res.time);
    if(res.status == 2) JackpotGetSlider_room3();
    if(res.status == 3) JackpotNewGame_room3();
}, function(res) {
    log('[ROOM #3] Ошибка в функции getStatus');
});

function getMerchBalance() {
    requestify.post(config.domain+'/api/getMerchBalance')
    .then(function(response) {
        var balance = JSON.parse(response.body);
        log('['+balance.type+'] '+balance.msg);
        setTimeout(getMerchBalance, 600000);
    },function(response){
        log('Ошибка в функции [getMerchBalance]');
        setTimeout(getMerchBalance, 1000);
    });
}

getMerchBalance();

function getRandomArbitrary(min, max) {
    return Math.random() * (max - min) + min;
}
    
function getRandomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}