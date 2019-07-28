var fs = require('fs'),
    options = {
        key: fs.readFileSync('/var/www/html/storage/bot/privkey.pem', 'utf8'),
        cert: fs.readFileSync('/var/www/html/storage/bot/fullchain.pem', 'utf8')
    },
    config          = require('./config.js'),
    app             = require('express')(),
    server          = require('https').createServer(options, app),
    io              = require('socket.io')(server),
    double          = new (require('./double'))(io, 'https://live-loto.com'),
    redis           = require('redis').createClient();

    redis.subscribe('roulette');
    redis.on('message', (channel, message) => {
        message = JSON.parse(message);
        if(channel == 'roulette' && message.type == 'back_timer') return double.startTimer(message.timer);
        console.log(channel, message);
        io.sockets.emit(channel, message);
    });

    double.start(); // start double game

    server.listen(2083);