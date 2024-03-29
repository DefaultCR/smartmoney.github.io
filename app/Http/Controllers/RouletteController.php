<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Auth;
use App\Settings;
use App\Roulette;
use App\RouletteBets;
use DB;

class RouletteController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->game = Roulette::orderBy('id', 'desc')->first();
        view()->share('game', $this->game);
    }

    public function test()
    {
        return $this->rotate(parent::makeProfit());
    }
    
    public function index()
    {
        $rotate = $this->settings->roulette_rotate2;
        $time = $this->settings->roulette_rotate_start-time()+$this->settings->double_timer; // заменить 7 на время таймера в секунда INTEGER
        if($this->game->status == 2 && $time > 0)
        {
            $rotate += ($this->settings->roulette_rotate-$this->settings->roulette_rotate2)*(1-($time/7));
        }
        $rotate2 = $this->settings->roulette_rotate;
        $bets = $this->getBets();
        $prices = $this->getPrices();
        $history = $this->getHistory();
        return view('pages.roulette', compact('bets', 'rotate', 'rotate2', 'time', 'prices','history'));
    }

    public function history() 
    {
        $games = Roulette::select('id', 'price', 'updated_at', 'winner_color', 'winner_num')->where('status', 3)->orderBy('id', 'desc')->limit(30)->get();
        return view('pages.rouletteHistory', compact('games'));
    }

    private function calcProfit()
    {
        $winSum = RouletteBets::where('round_id', $this->game->id)->where('type', $this->game->winner_color)->sum('price');
        $winSum *= ($this->game->winner_color == 'green') ? 14 : 2;
        $gameSum = RouletteBets::where('round_id', $this->game->id)->sum('price');
        return $gameSum-$winSum;
    }

    private function getPrices()
    {
        $query = RouletteBets::where('round_id', $this->game->id)
                    ->select(DB::raw('SUM(price) as value'), 'type')
                    ->groupBy('type')
                    ->get();

        $list = [];
        foreach($query as $l) $list[$l->type] = $l->value;
        return $list;
    }

    public function getHistory()
    {
        $query = Roulette::where('status', 3)->select('winner_num', 'winner_color', 'id')->orderBy('id','desc')->limit(45)->get();
        return $query;
    }

    public function addBet(Request $r)
    {
        /*if($this->user->id != 1) return [
            'success' => false,
            'msg' => 'На сайте ведутся технические работы!'
        ];*/
        $value = floatval($r->get('bet'));
        // Проверка типа данных value
        if(gettype($value) != 'double') return [
            'success' => false,
            'msg' => 'Не удалось определить тип данных!'
        ];

        if($value < $this->settings->double_min_bet) return [
            'success' => false,
            'msg' => 'Минимальная сумма ставки - '.$this->settings->double_min_bet
        ];

        if($this->settings->double_max_bet > 0 && $value > $this->settings->double_max_bet) return [
            'success' => false,
            'msg' => 'Максимальная сумма ставки - '.$this->settings->double_max_bet
        ];

        if($this->game->status > 1) return [
            'success' => false,
            'msg' => 'Ставки в эту игру закрыты!'
        ];

        // проверка баланса
        if($this->user->balance < $value) return [
            'success' => false,
            'msg' => 'Недостаточно баланса!'
        ];

        // получение ставок пользователя
        $bets = RouletteBets::where([
            'user_id' => $this->user->id,
            'round_id' => $this->game->id
        ])->select('type as color')->groupBy('color')->get();

        $ban = 'none';
        foreach($bets as $b) if($b->color != 'green') $ban = $b->color;
        if($ban != 'none') $ban = ($ban == 'red') ? 'black' : 'red';

        if($r->get('type') == $ban) return [
            'success' => false,
            'msg' => 'Вы не можете сделать ставку на этот цвет!',
            'bets' => $bets
        ];

        // Минусуем баланс
        $this->user->balance -= $value;
        $this->user->save();

        $this->game->price += $value;
        $this->game->save();

        $bet = RouletteBets::create([
            'user_id' => $this->user->id,
            'round_id' => $this->game->id,
            'price' => $value,
            'type' => $r->get('type')
        ]);

        $this->redis->publish('updateBalance', json_encode([
            'id' => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        $this->emit([
            'type' => 'bets',
            'bets' => $this->getBets(),
            'prices' => $this->getPrices()
        ]);

        $this->startTimer();

        return [
            'success' => true,
            'msg' => 'Ваша ставка вошла в игру!'
        ];
    }

    private function startTimer()
    {
        if($this->game->status > 0) return;

        $this->game->status = 1;
        $this->game->save();

        return $this->emit([
            'type' => 'back_timer',
            'timer' => $this->settings->double_timer // заменить на время таймера
        ]);
    }

    public function rotate($profit)
    {
        $list = [
            [0,     'green',    0,     14],
            [337,   'red',    	1,     2],
            [288,   'red',      2,     2],
            [240,   'red',    	3,     2],
            [193,   'red',      4,     2],
            [145,   'red',    	5,     2],
            [97,    'red',      6,     2],
            [48,    'red',    	7,     2],
            [312,   'black',    8,     2],
            [264,   'black',    9,     2],
            [216,   'black',    10,    2],
            [169,   'black',    11,    2],
            [121,   'black',    12,    2],
            [72,    'black',    13,    2],
            [24,    'black',    14,    2]
        ];

        if($profit)
        {
            $lastGreen = Roulette::where('status', 3)->where('winner_color', 'green')->first();
            $prices = $this->getPrices();
            $pricesList = [];
            $colors = ['red', 'green', 'black'];
            foreach($colors as $color) $pricesList[] = [
                'color' => $color,
                'value' => ((isset($prices[$color])) ? $prices[$color] : 0)*(($color == 'green') ? 14 : 2)
            ];
            usort($pricesList, function($a, $b) {
                return($a['value']-$b['value']);
            });
            
            $needColor = $pricesList[0]['color'];
            // check green
            if($pricesList[0]['color'] == 'green') {
                if(!is_null($lastGreen) && ($this->game->id-$lastGreen->id) > 12) {
                    $needColor = 'green';
                } else if(is_null($lastGreen)) {
                    $needColor = 'green';
                } else {
                    $needColor = $pricesList[1]['color'];
                }
            }

            $rotateList = [];
            foreach($list as $l) if($l[1] == $needColor) $rotateList[] = $l;
            if(count($rotateList) > 0) return $rotateList[mt_rand(0, count($rotateList)-1)];
        }

        if($this->game->winner_num !== null) foreach($list as $l) if($l[2] == $this->game->winner_num) return $l;

        // зеленый
        if(parent::tf(2)) return $list[0];

        return $list[mt_rand(1, count($list)-1)];
    }

    public function getSlider()
    {
        $box = $this->rotate(parent::makeProfit());
        $rotate = ((floor($this->settings->roulette_rotate/360)*360)+360)+(360*5)+$box[0];

        $this->game->winner_num = $box[2];
        $this->game->winner_color = $box[1];
        $this->game->save();

        $this->settings->roulette_rotate = $rotate;
        $this->settings->roulette_rotate_start = time();
        $this->settings->save();

        $this->emit([
            'type' => 'slider',
            'slider' => [
                'rotate' => $this->settings->roulette_rotate,
                'color' => $this->game->winner_color,
                'num' => $this->game->winner_num,
                'time' => 7000, // !important time in ms
                'timeToNewGame' => 3000, // !important time in ms
            ]
        ]);

        return [
            'number' => $this->game->winner_num,
            'color' => $this->game->winner_color,
            'time' => 10000 // !important (time  + timeToNewGame)
        ];

    }

    public function getBet(Request $r)
    {
        if($r->get('type') == 'all') return $this->user->balance;
        $bet = RouletteBets::where('user_id', $this->user->id)->orderBy('id', 'desc')->first();
        return (is_null($bet)) ? 0 : $bet->price;
    }

    public function newGame()
    {
        $this->settings->roulette_rotate = $this->settings->roulette_rotate-(floor($this->settings->roulette_rotate/360)*360);
        $this->settings->roulette_rotate2 = $this->settings->roulette_rotate;
        $this->settings->sys_profit += $this->calcProfit();
        $this->settings->save();

        // получаем выигрышные ставки
        $bets = RouletteBets::select(DB::raw('SUM(price) as price'), 'user_id')->where('round_id', $this->game->id)->where('type', $this->game->winner_color)->groupBy('user_id')->get();

        // множитель 
        $multiplier = ($this->game->winner_color == 'green') ? 14 : 2;
        foreach($bets as $u)
        {
            $user = User::where('id', $u->user_id)->first();
            if(!is_null($user)) {
                $user->balance += $u->price*$multiplier;
                $user->save();

                $this->redis->publish('updateBalance', json_encode([
                    'id' => $user->id,
                    'balance' => $user->balance
                ]));
                
                // update balance
            }
        }

        $this->emit([
            'type' => 'newGame',
            'slider' => [
                'rotate' => $this->settings->roulette_rotate,
                'time' => $this->settings->double_timer // !important, timer time
            ],
            'history' => [
                'num' => $this->game->winner_num,
                'color' => $this->game->winner_color
            ]
        ]);

        $this->game = Roulette::create();

        // redis new Game
        return [
            'id' => $this->game->id
        ];

    }

    public function updateStatus(Request $r)
    {
        $this->game->status = $r->get('status');
        $this->game->save();
        
        return [
            'success' => true
        ];
    }

    public function getGame()
    {
        return [
            'id' => $this->game->id,
            'status' => $this->game->status,
            'time' => $this->settings->double_timer // fix
        ];
    }

    private function getBets()
    {
        $bets = DB::table('roulettebets')
                    ->where('roulettebets.round_id', $this->game->id)
                    ->select('roulettebets.user_id', DB::raw('SUM(roulettebets.price) as value'), 'users.username', 'users.avatar', 'roulettebets.type')
                    ->join('users', 'users.id', '=', 'roulettebets.user_id')
                    ->groupBy('roulettebets.user_id', 'roulettebets.type')
                    ->orderBy('value', 'desc')
                    ->get();

        return $bets;
    }

    private function emit($array)
    {
        return $this->redis->publish('roulette', json_encode($array));
    }
	
	public function gotDouble(Request $r) {
		$color = $r->get('color');
		$number = '';
		
		if($this->game->status > 1) return [
			'msg'       => 'Игра началась, вы не можете подкрутить!',
			'type'      => 'error'
		];
        
		if(!$this->game->id) return [
			'msg'       => 'Не удалось получить номер игры!',
			'type'      => 'error'
		];
		
		if(!$color) return [
			'msg'       => 'Не удалось получить цвет!',
			'type'      => 'error'
		];
		
		$list = [
            [0,     'green',    0,     14],
            [337,   'red',    	1,     2],
            [288,   'red',      2,     2],
            [240,   'red',    	3,     2],
            [193,   'red',      4,     2],
            [145,   'red',    	5,     2],
            [97,    'red',      6,     2],
            [48,    'red',    	7,     2],
            [312,   'black',    8,     2],
            [264,   'black',    9,     2],
            [216,   'black',    10,    2],
            [169,   'black',    11,    2],
            [121,   'black',    12,    2],
            [72,    'black',    13,    2],
            [24,    'black',    14,    2]
        ];
        
        shuffle($list);
		
		if ($color == 'green') $number = 0;
		if ($color == 'red') $number = mt_rand(1, 7);
		if ($color == 'black') $number = mt_rand(8, 14);
		
		foreach ($list as $l)
		{
			if($l[2] == $number)
			{
				$data = $l;
			}
		}

		Roulette::where('id', $this->game->id)->update([
			'winner_color'      => $data[1],
			'winner_num'     => $data[2]
		]);
		
		return [
			'msg'       => 'Вы подкрутили на '.$color.' цвет!',
			'type'      => 'success'
		];
	}
}