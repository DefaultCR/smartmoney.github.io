var requestify = require('requestify');

var crash = function(io) {
    this.io = io;
    this.domain = 'https://live-loto.com';
    this.animate = true;
    this.stop = true;
}

crash.prototype.log = function(log)
{
    console.log('[CRASH] ' + log);
}

crash.prototype.updateStatus = function(status, multiplier)
{
    if(status == 3) multiplier = this._now.toFixed(2);
    this.post('/api/crash/updateStatus', {
        status : status,
        multiplier : multiplier || false
    }, (done, res) => {
        if(!done) return;
        if(res.success) this.log('Игра #' + this.id + ' изменила статус на ' + status);
    });
}

crash.prototype.getSlider = function()
{
    this.updateStatus(2); // slider
    this.post('/api/crash/slider', {}, (success, res) => {
        if(!success) return;
        this.log('Игра #' + this.id + ' получила график! (x' + res.multiplier + ')');
        this.animateTo(res.multiplier);
    });
}

crash.prototype.newGame = function() 
{
    this.log('Игра #' + this.id + ' закончилась!');
    this.post('/api/crash/newGame', {}, (success, res) => {
        if(!success) return;
        this.id = res.id;
        this.log('Новая игра #' + this.id);
        
        this._data = [[0,1]];
        this._now = 1;
        this._options.xaxis.max = Math.max(1, 5000/2000);
        this._options.yaxis.max = Math.max(1.003004504503377*1, 2);
        this._options.colors[0] = this._colors.yellow;
        this.emit('crash', {
            type : 'slider',
            data : this._data,
            options : this._options,
            m : false,
            color : this._colors.yellow
        });

        this.emit('crash', {
            type : 'newGame'
        });

        this.startTimer(res.time);
    });
}

crash.prototype.animateTo = function(float)
{
    this.log('Игра #' + this.id + ' показывает график!');
    this.animate = true;
    this.stop = true;

    this._i = 0;
    this._now = 1;
    this._data = [[0, 1]];
    this.float = parseFloat(float);

    this._options.colors[0] = this._colors.yellow;


    this.animateInterval = setInterval(() => {
        if(this.animate)
        {
            this._i++;
            this._now = parseFloat(Math.pow(Math.E, 0.00006*this._i*1000/20)); 
            this._data.push([this._i, this._now]);
            this._options.xaxis.max = Math.max(this._i, 5000/20);
            this._options.yaxis.max = Math.max(this._now*1, 2);
    
            // console.log(this._now.toFixed(2));
            this.emit('crash', {
                type : 'slider',
                data : this._data,
                options : this._options,
                m : parseFloat(this._now.toFixed(2)),
                multiplier : this._now.toFixed(2) + 'x',
                color : this.getColors(this._now)
            });
        }

        if(this._now >= this.float && this.animate) 
        {
            return this.stopAnimate(true);
        }
    }, 50);
} 

crash.prototype.stopAnimate = function(force)
{   
    if(!this.stop) return;
    this.stop = false;
    this.animate = false;
    var mult = (force) ? parseFloat(this.float.toFixed(2)) : parseFloat(this._now.toFixed(2));
    this._options.colors[0] = this._colors.red;
    this.emit('crash', {
        type : 'slider',
        data : this._data,
        options : this._options,
        m : mult,
        multiplier : 'График упал на ' + mult.toFixed(2) + 'x',
        color : this._colors.red
    });
    clearInterval(this.animateInterval);
    this.updateStatus(3);
    setTimeout(() => {
        this.newGame();
    }, 3000);
}

crash.prototype.startTimer = function(time)
{
    // time = 120;
    this.log('Игра #' + this.id + ' запустила таймер!');
    this.updateStatus(1);

    this.time = parseFloat(time);
    this.timerInterval = setInterval(() => {
        this.time -= 0.1;
        this.emit('crash', {
            type : 'timer',
            time : this.time
        });
        /*if(this.time.toFixed(1) == Math.round(this.time).toFixed(1)) this.log('Таймер ' + Math.round(this.time).toFixed(0) + 's');*/
        if(this.time <= 0)
        {
            clearInterval(this.timerInterval);
            // get slider
            return this.getSlider();
        }
    }, 100);
}

crash.prototype.post = function(url, data, done)
{
    requestify.post(this.domain + url, data)
    .then((res) => {
        return done(true, JSON.parse(res.body));
    }, (err) => {
        this.log('Error with request ' + url);
        return done(false, null);
    });
}

crash.prototype.withdraw = function(hash, multiplier, res)
{
    // console.log(hash, multiplier);

    // console.log(hash, multiplier);
    // if(multiplier > this.float || multiplier > this._now) return res({
    //     success : false,
    //     msg : 'Ошибка!'
    // });

    this.post('/api/crash/withdraw', {
        hash : hash,
        multiplier : multiplier
    }, (success, response) => {
        if(!success) return res({
            success : false,
            msg : 'Ошибка!'
        });

        return res(response);
    });
}

crash.prototype._options = {
    xaxis: {
        show: false
    },
    series: {
        lines: { fill: true },
    },
    grid: {
        borderColor: "#647371",
        borderWidth: {
            top: 0,
            right: 0,
            left: 2,
            bottom: 2
        }
    },
    yaxis: {
        min: 1
    },
    colors : [],
}

crash.prototype._colors = {
    one: '#cf1213',
    two: '#a8128f',
    three: '#7118d4',
    four: '#1337d4',
    five: '#037cf3',
    yellow: '#fea700',
    red: '#aa3737',
    border: '#647371'
}

crash.prototype.getColors = function(n)
{
    if(n > 6.49) return this._colors.five;
    if(n > 4.49) return this._colors.four;
    if(n > 2.99) return this._colors.three;
    if(n > 1.99) return this._colors.two;
    return this._colors.one;
}

crash.prototype.emit = function(channel, message)
{
    this.io.sockets.emit(channel, message);
}

crash.prototype.start = function()
{
    this.post('/api/crash/getGame', {}, (success, res) => {
        if(!success) return;
        this.id = res.id;
        if(res.status < 2) this.startTimer(res.time);
        if(res.status == 2) this.getSlider();
        if(res.status == 3) this.newGame();
        this.log('Игра #' + this.id + ' продолжается!');
    });
}

module.exports = crash; 