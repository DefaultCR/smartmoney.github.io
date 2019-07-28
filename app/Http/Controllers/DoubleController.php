<?php

namespace App\Http\Controllers;

use DB;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Auth;
use App\Double;
use App\DoubleBets;
use App\Settings;
use Redis;

class DoubleController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->game     = Double::orderBy('id', 'desc')->first();
        $this->rotate   = $this->redis->get('rotate');
        view()->share('rotate', $this->rotate);
        view()->share('games', $this->getLastGamesData());
        view()->share('bets', $this->getBets());
        view()->share('game', $this->game);
        view()->share('title', 'Double');
        view()->share('time', $this->settings->double_timer);
    }
    
    public function double()
    {
        return view('pages.double');
    }
    
    public function test2()
    {
        return random_int(0, 14);
    }
    
    public function history()
    {
        $games = Double::where('status', 3)->orderBy('id', 'desc')->limit(25)->get();
        return view('pages.doubleHistory', compact('games'));
    }
    
    public function addBet(Request $r)
    {
		if (\Cache::has('bet.user.' . $this->user->id)) return 0;
        \Cache::put('bet.user.' . $this->user->id, '', 0.05);
        if($this->user->id != 2) return [
            'success' => false,
            'msg' => 'На сайте ведутся технические работы!'
        ];
        $type = $r->get('type');
        $value = $r->get('value');
        $moneytick = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
        if(Auth::guest()) {
            return response()->json(['message' => 'Вы не авторизованы!', 'status' => 'error']);
        }
        
        if($moneytick < $this->settings->double_min_bet) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Минимальная сумма ставки - '.$this->settings->double_min_bet,
                'icon'  => 'error'
            ]));
            return;
        }
        
        if($moneytick > $this->settings->double_max_bet) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Максимальная сумма ставки - '.$this->settings->double_max_bet,
                'icon'  => 'error'
            ]));
            return;
        }
        
        if($moneytick > $this->user->balance) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Недостаточно монет на балансе!',
                'icon'  => 'error'
            ]));
            return;
        }
        
        if($this->game->status > 1) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Ставки в эту игру закрыты!',
                'icon'  => 'error'
            ]));
            return;
        }

        // Проверки на два цвета
        $bet = DoubleBets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->where('type', '!=', 'green')->first();
        
        if(!is_null($bet)) {
            if(($type != 'green') && ($bet->type != $type)) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Вы можете поставить только на : '.$bet->type.' , green',
                    'icon'  => 'error'
                ]));
                return;
            }
        }
        
        $this->user->balance -= $moneytick;
        $this->user->save();
        
        DoubleBets::insert([
            'user_id'   => $this->user->id,
            'game_id'   => $this->game->id,
            'type'      => $r->get('type'),
            'value'     => $r->get('value')
        ]);
        
        switch($type) {
            case 'red' :
                $this->game->price_red += $moneytick;
            break;
            case 'green' :
                $this->game->price_zero += $moneytick;
            break;
            case 'black' :
                $this->game->price_black += $moneytick;
            break;
        }
        
        $this->game->price += $moneytick;
        $this->game->save();
        
        if($this->user->referred_by) {
            $ref = User::where('affiliate_id', $this->user->referred_by)->first();
            $ref_perc = $this->getRefer($ref->id);
            $ref->ref_money += $moneytick/100*$ref_perc;
            $ref->ref_money_history += $moneytick/100*$ref_perc;
            $ref->save();
        }
        
        $this->getTimer();
        
        $returnValue = [
            'user_id'   => $this->user->id,
            'username'  => $this->user->username,
            'avatar'    => $this->user->avatar,
            'type'      => $type,
            'value'     => $moneytick,
            'allValues' => [
                'red' => DoubleBets::where('user_id', $this->user->id)->where('game_id', $this->game->id)->where('type', 'red')->sum('value'),
                'green' => DoubleBets::where('user_id', $this->user->id)->where('game_id', $this->game->id)->where('type', 'green')->sum('value'),
                'black' => DoubleBets::where('user_id', $this->user->id)->where('game_id', $this->game->id)->where('type', 'black')->sum('value')
            ],
            'global'    => [
                'red'   => $this->game->price_red,
                'green'  => $this->game->price_zero,
                'black' => $this->game->price_black
            ]
        ];
        
        $this->redis->publish('double.newBet', json_encode($returnValue));
        
        $this->redis->publish('updateBalance', json_encode([
            'id'      => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        return response()->json(['message' => 'Ваша ставка принята!', 'status' => 'success']);
    }
    
    private function getRefer($id) {
        $ref_count = User::where('referred_by', $id)->count();
        if($ref_count < 10) {
            $ref_perc = 0.5;
        } elseif($ref_count >= 10 && $ref_count < 100) {
            $ref_perc = 0.7;
        } elseif($ref_count >= 100 && $ref_count < 500) {
            $ref_perc = 1;
        } elseif($ref_count > 500) {
            $ref_perc = 1.5;
        }
        return $ref_perc;
    }
    
    public function getTimer()
    {   
        if($this->game->status > 0) 
		{
			$this->getCheckTwist();
			return;
		}
        
        $list = [
            // [0,     'green',    0,     14],
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
        
        $key = floor(count($list)*$this->game->random_number);
		
        if(!isset($list[$key])) $key--;
		
        $data = $list[$key];

        if(mt_rand(0, 100) == 25) $data = [0, 'green', 0, 14];
        
        if(!isset($data[2])) {
            $key--;
            $data = $list[$key];
        }
        
		$double_crack = DB::table('double_crack')->where('game_id', $this->game->id)->first();
        
		if ($double_crack) {
			$this->game->winner_color   = $double_crack->color;
			$game_color					= $double_crack->color;
			
			$this->game->winner_num     = $double_crack->num;
			$game_number 				= $double_crack->num;
			
			$this->game->winner_x       = $double_crack->x;
			$rotate = 360-($this->rotate-floor($this->rotate/360)*360)+$double_crack->rotate+$this->rotate+1080;
			
			$this->game->status         = 1;
			$this->game->save();
		} else {
			$this->game->winner_color   = $data[1];
			$game_color  				= $data[1];
			
			$this->game->winner_num     = $data[2];
			$game_number   				= $data[2];
			
			$this->game->winner_x       = $data[3];
			$rotate = 360-($this->rotate-floor($this->rotate/360)*360)+$data[0]+$this->rotate+1080;
			
			$this->game->status         = 1;
			$this->game->save();
		}
        
        $winner_value = DoubleBets::where('game_id', $this->game->id)->where('type', $this->game->winner_color)->sum('value')*$this->game->winner_x;
        
		$returnValue = [
			'time'          => $this->settings->double_timer,
			'rotate'        => $rotate,
			'color'         => $game_color,
			'number'        => $game_number,
			'random_number' => $this->game->random_number,
			'winner_value'  => $winner_value
		];
	
        $this->redis->publish('double.timer', json_encode($returnValue));
    }
	
	public function getCheckTwist()
	{
		$double_crack = DB::table('double_crack')->where('game_id', $this->game->id)->first();
		
		if(!$double_crack) return;
		
		$this->game->winner_color   = $double_crack->color;
		$game_color					= $double_crack->color;
			
		$this->game->winner_num     = $double_crack->num;
		$game_number 				= $double_crack->num;
			
		$this->game->winner_x       = $double_crack->x;
		$rotate = 360-($this->rotate-floor($this->rotate/360)*360)+$double_crack->rotate+$this->rotate+1080;
		
		$this->game->status         = 1;
		$this->game->save();
		
		$winner_value = DoubleBets::where('game_id', $this->game->id)->where('type', $this->game->winner_color)->sum('value')*$this->game->winner_x;
        
		$returnValue = [
			'time'          => $this->settings->double_timer,
			'rotate'        => $rotate,
			'color'         => $game_color,
			'number'        => $game_number,
			'random_number' => $this->game->random_number,
			'winner_value'  => $winner_value
		];
	
        $this->redis->publish('double.timer', json_encode($returnValue));
	}
	
	public function checkWinnerInfo()
	{
		$double_crack = DB::table('double_crack')->where('game_id', $this->game->id)->first();
		
		if($double_crack) {
			
			$game_color					= $double_crack->color;
			$game_number 				= $double_crack->num;
			
			$rotate = 360-($this->rotate-floor($this->rotate/360)*360)+$double_crack->rotate+$this->rotate+1080;
			
			$winner_value = DoubleBets::where('game_id', $this->game->id)->where('type', $this->game->winner_color)->sum('value')*$this->game->winner_x;
			
			return response()->json([
				'time'          => $this->settings->double_timer,
				'rotate'        => $rotate,
				'color'         => $game_color,
				'number'        => $game_number,
	'number1' 		=> $this->game->winner_num+1,
				'random_number' => $this->game->random_number,
				'winner_value'  => $winner_value
			]);
		} else {
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
			
			$winner_value = DoubleBets::where('game_id', $this->game->id)->where('type', $this->game->winner_color)->sum('value')*$this->game->winner_x;

			foreach ($list as $l)
			{
				if($l[2] == $this->game->winner_num)
				{
					$rotate = 360-($this->rotate-floor($this->rotate/360)*360)+$l[0]+$this->rotate+1080;
				}
			}

			return response()->json([
				'time'          => $this->settings->double_timer,
				'rotate'        => $rotate,
				'color'         => $this->game->winner_color,
				'number' 		=> $this->game->winner_num,
	'number1' 		=> $this->game->winner_num+1,
				'random_number' => $this->game->random_number,

				'winner_value'  => 'null'
			]);
		}
	}
	
    public function updateStatus(Request $r)
    {
        $this->game->status = $r->get('status');
        $this->game->save();
    }
    
    public function newGame()
    {
        $this->game->status = 3;
        $this->game->save();
		
        // Отдаем выигрыш победителям.
        $bets = DoubleBets::where('game_id', $this->game->id)->get();
        foreach($bets as $bet) {
            if($bet->type == $this->game->winner_color) {
                $user = User::where('id', $bet->user_id)->first();
                if($user) {
                    DoubleBets::where('id', $bet->id)->update([
                        'is_winner'     => 1,
                        'value_winner'  => $bet->value*$this->game->winner_x
                    ]);
                    $user->balance        += $bet->value*$this->game->winner_x;
                    $user->save();
					
					$this->redis->publish('updateBalance', json_encode([
						'id'    => $user->id,
						'balance' => $user->balance
					]));
                }
            }
        }
		
        // Создаем новую игру
        Double::insert([
            'random_number' => '0.'.mt_rand(100000000, 999999999).mt_rand(100000000, 999999999).mt_rand(100000000, 999999999).mt_rand(100, 999)
        ]);
        
        $this->game = Double::orderBy('id', 'desc')->first();
        
        $returnValue = [
            'time' => $this->settings->double_timer
        ];
        
        $this->redis->publish('double.newGame', json_encode($returnValue));
    }
    
    public function getGameId()
    {
        $str = '';
        $strlen = strlen($this->game->id);
        $u = 7-$strlen;
        for($i = 0; $i < $u; $i++) {
            $str .= '0';  
        }
        $str .= $this->game->id;
        return $str;
    }
    
    public function getLastGamesData()
    {
        $list = [];
        
        $games = Double::where('status', 3)->orderBy('id', 'desc')->limit(38)->get();
        foreach($games as $game) {
            $list[] = [
                'num'   => $game->winner_num,
                'color' => $game->winner_color
            ];
        }
        
        return $list;
    }
    
    public function getBets()
    {
        if(!$this->game) $this->game = Double::create();
        $bets = DoubleBets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        foreach($bets as $i => $bet) {
            $user = User::where('id', $bet->user_id)->first();
            $bets[$i]->username = $user->username;
            $bets[$i]->avatar = $user->avatar;
        } 
        return $bets;
    }
    
    public static function getLastGame() {
        $games = Double::orderBy('id', 'desc')->first();
        return $games;
    }
    
    public function getStatus()
    {
        if(($this->game->status != 0) && ($this->game->status < 3)) $this->getTimer();
        return [
            'id'        => $this->game->id,
            'status'    => $this->game->status
        ];
    }
    
    public function gotDouble(Request $r) {
		$double_twist = DB::table('double_crack')->where('game_id', $this->game->id)->first();
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
        
		if(!$double_twist) {
            DB::table('double_crack')->insert([
                'game_id'   => $this->game->id,
                'color'      => $data[1],
                'num'     => $data[2],
                'x'     => $data[3],
                'rotate'     => $data[0]
            ]);
        } else {
            DB::table('double_crack')->where('game_id', $this->game->id)->update([
                'color'      => $data[1],
                'num'     => $data[2],
                'x'     => $data[3],
                'rotate'     => $data[0]
            ]);
        }
		
		Double::where('id', $this->game->id)->update([
            'winner_color' => $data[1],
            'winner_num' => $data[2],
            'winner_x' => $data[3]
        ]);
		
		return [
			'msg'       => 'Вы подкрутили на '.$color.' цвет!',
			'type'      => 'success'
		];
	}
}