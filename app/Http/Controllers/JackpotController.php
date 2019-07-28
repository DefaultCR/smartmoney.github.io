<?php namespace App\Http\Controllers;

use Auth;
use App\User;
use App\Room1;
use App\Room2;
use App\Room3;
use App\Room1_bets;
use App\Room2_bets;
use App\Room3_bets;
use App\Settings;
use App\Payments;
use App\Withdraw;
use App\SuccessPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Carbon\Carbon;

class JackpotController extends Controller
{
	protected $lastTicket = 0;
	
    public function __construct(Request $r)
    {
        parent::__construct();
        $room = $r->get('room');
        if(!$room) $room = 1;
        $this->room = $room;
        if($room == 1) $this->game = Room1::orderBy('id', 'desc')->first();
        if($room == 2) $this->game = Room2::orderBy('id', 'desc')->first();
        if($room == 3) $this->game = Room3::orderBy('id', 'desc')->first();
        $this->min_dep_1 = $this->settings->jackpot_min_bet_room_1;
        $this->max_dep_1 = $this->settings->jackpot_max_bet_room_1;
        $this->min_dep_2 = $this->settings->jackpot_min_bet_room_2;
        $this->max_dep_2 = $this->settings->jackpot_max_bet_room_2;
        $this->min_dep_3 = $this->settings->jackpot_min_bet_room_3;
        $this->max_dep_3 = $this->settings->jackpot_max_bet_room_3;
        $bank_1 = $this->getBank(1);
        $bank_2 = $this->getBank(2);
        $bank_3 = $this->getBank(3);
		$this->lastTicket = $this->redis->get('last.ticket_'.$room.'.' . $this->game->id);
        if (is_null($this->lastTicket)) $this->lastTicket = 0;
        view()->share('min_bet_1', $this->min_dep_1);
        view()->share('min_bet_2', $this->min_dep_2);
        view()->share('min_bet_3', $this->min_dep_3);
        view()->share('max_bet_1', $this->max_dep_1);
        view()->share('max_bet_2', $this->max_dep_2);
        view()->share('max_bet_3', $this->max_dep_3);
        view()->share('bank_1', $bank_1);
        view()->share('bank_2', $bank_2);
        view()->share('bank_3', $bank_3);
        view()->share('game', $this->getGame($room));
        view()->share('bets', $this->getGameBets($room));
        view()->share('line', $this->getLine($this->getChancesOfGame($room, $this->game->id)));
        view()->share('title', 'Jackpot');
        view()->share('time', $this->getTime($room));
    }
    
    private function getGame($room) {
        if($room == 1) $this->game = Room1::orderBy('id', 'desc')->first();
        if($room == 2) $this->game = Room2::orderBy('id', 'desc')->first();
        if($room == 3) $this->game = Room3::orderBy('id', 'desc')->first();
        return $this->game;
    }
    
    private function getBank($room) {
        if($room == 1) {
            $this->game = Room1::orderBy('id', 'desc')->first();
            if(!$this->game) $this->game = Room1::create();
        }
        if($room == 2) {
            $this->game = Room2::orderBy('id', 'desc')->first();
            if(!$this->game) $this->game = Room2::create();
        }
        if($room == 3) {
            $this->game = Room3::orderBy('id', 'desc')->first();
            if(!$this->game) $this->game = Room3::create();
        }
        
        return $this->game->price;
    }
	
	public function test() {
        $room = 1;
        $id = 30;
		if($room == 1) {
            $game = Room1::where('id', $id)->first();
            $all_bets = Room1_bets::where('game_id', $id)->sum('sum');
            $w_bet = Room1_bets::where('game_id', $id)->where('user_id', $game->winner_id)->sum('sum');
        }
		if($room == 2) {
            $game = Room2::where('id', $id)->first();
            $all_bets = Room2_bets::where('game_id', $id)->sum('sum');
            $w_bet = Room2_bets::where('game_id', $id)->where('user_id', $game->winner_id)->sum('sum');
        }
		if($room == 3) {
            $game = Room3::where('id', $id)->first();
            $all_bets = Room3_bets::where('game_id', $id)->sum('sum');
            $w_bet = Room3_bets::where('game_id', $id)->where('user_id', $game->winner_id)->sum('sum');
        }
        $sum = floor($w_bet + (($all_bets - $w_bet) - ($all_bets - $w_bet)/100*20));
		$user = User::where('id', $game->winner_id)->first();

		$user->balance += $sum;
		$user->save();
		
		$this->redis->publish('updateBalance', json_encode([
            'id'    => $user->id,
            'balance' => $user->balance
        ]));
	}
	
	public function jackpot(Request $r) {
        $room = $r->get('room');
        if(!$room) $room = 1;
        
		return view('pages.jackpot', compact('room'));
	}
	
	private function getColor() {
        $color = str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
		return $color;
	}
    
    public function newBet(Request $r) {
		if (\Cache::has('bet.user.' . $this->user->id)) {
			$this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Вы слишком часто делаете ставку!',
                'icon'  => 'error'
            ]));
            return;
		}
        \Cache::put('bet.user.' . $this->user->id, '', 0.10);
        $room = $r->get('room');
        $sum = floor($r->get('sum'));
        $moneytick = preg_replace('/[^\p{L}\p{N}\s]/u', '', $sum);
        if($room == 1) $userbets = Room1_bets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->count();
        if($room == 2) $userbets = Room2_bets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->count();
        if($room == 3) $userbets = Room3_bets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->count();
        if($room == 1) $usersum = Room1_bets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->sum('sum');
        if($room == 2) $usersum = Room2_bets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->sum('sum');
        if($room == 3) $usersum = Room3_bets::where('game_id', $this->game->id)->where('user_id', $this->user->id)->sum('sum');
        if($room == 1) $bets = Room1_bets::where('game_id', $this->game->id)->get();
        if($room == 2) $bets = Room2_bets::where('game_id', $this->game->id)->get();
        if($room == 3) $bets = Room3_bets::where('game_id', $this->game->id)->get();
		
        if($userbets >= $this->settings->jackpot_maxbet) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Вы не можете сделать больше '.$this->settings->jackpot_maxbet.' ставок за одну игру!',
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
        if($room == 1) {
            if($moneytick < $this->settings->jackpot_min_bet_room_1) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Минимальная ставка - '.$this->settings->jackpot_min_bet_room_1.' монет.',
                    'icon'  => 'error'
                ]));
                return;
            }
            if($moneytick > $this->settings->jackpot_max_bet_room_1) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Максимальная ставка - '.$this->settings->jackpot_max_bet_room_1.' монет.',
                    'icon'  => 'error'
                ]));
                return;
            }
        }
        if($room == 2) {
            if($moneytick < $this->settings->jackpot_min_bet_room_2) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Минимальная ставка - '.$this->settings->jackpot_min_bet_room_2.' монет.',
                    'icon'  => 'error'
                ]));
                return;
            }
            if($moneytick > $this->settings->jackpot_max_bet_room_2) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Максимальная ставка - '.$this->settings->jackpot_max_bet_room_2.' монет.',
                    'icon'  => 'error'
                ]));
                return;
            }
        }
        if($room == 3) {
            if($moneytick < $this->settings->jackpot_min_bet_room_3) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Минимальная ставка - '.$this->settings->jackpot_min_bet_room_3.' монет.',
                    'icon'  => 'error'
                ]));
                return;
            }
            if($moneytick > $this->settings->jackpot_max_bet_room_3) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Максимальная ставка - '.$this->settings->jackpot_max_bet_room_3.' монет.',
                    'icon'  => 'error'
                ]));
                return;
            }
        }
        
        
        if(!$moneytick) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Вы не ввели сумму ставки!',
                'icon'  => 'error'
            ]));
            return;
        }
        
        if($moneytick > $this->user->balance) {
            $this->redis->publish('message', json_encode([
                'user'  => $this->user->id,
                'msg'   => 'Не хватает монет для ставки!',
                'icon'  => 'error'
            ]));
            return;
        }
        
        $getcolor = $this->getColor();
        
        /*foreach($bets as $usr) {
        $getIp = User::where('id', $usr->user_id)->first();
        if(isset($this->user->ip)) {
           if($getIp->ip == $this->user->ip) {
                $this->redis->publish('message', json_encode([
                    'user'  => $this->user->id,
                    'msg'   => 'Вы уже сделали ставку с другого аккаунта!',
                    'icon'  => 'error'
                ]));
                return;
            } 
        }
        }*/
        
        foreach($bets as $check) {
            if($check->color == $getcolor) {
                $getcolor = $this->getColor();
            }
            if($check->user_id == $this->user->id) {
                $getcolor = $check->color;
            }
        }
		
		$this->lastTicket = $this->redis->get('last.ticket_'.$room.'.' . $this->game->id);
        if (is_null($this->lastTicket)) $this->lastTicket = 0;
		
		$ticketFrom = 1;
        if($this->lastTicket != 0) $ticketFrom = $this->lastTicket + 1;
        
        $bet = [
            'game_id'   => $this->game->id,
            'user_id'   => $this->user->id,
            'sum'       => $moneytick,
            'color'     => $getcolor,
            'from'      => null,
            'to'        => null
        ];
		
		$ticketTo = $ticketFrom + floor($bet['sum']*1);
        $this->redis->set('last.ticket_'.$room.'.' . $this->game->id, $ticketTo);
        
        $bet['from']    = $ticketFrom;
        $bet['to']      = $ticketTo;
        
        $this->user->balance -= $moneytick;
        $this->user->save();
		
        if($this->user->referred_by) {
            $ref = User::where('affiliate_id', $this->user->referred_by)->first();
            $ref_perc = $this->getRefer($ref->id);
            $ref->ref_money += $moneytick/100*$ref_perc;
            $ref->ref_money_history += $moneytick/100*$ref_perc;
            $ref->save();
        }
        
        $this->redis->publish('updateBalance', json_encode([
            'id'    => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        if($room == 1) Room1_bets::insert($bet);
        if($room == 2) Room2_bets::insert($bet);
        if($room == 3) Room3_bets::insert($bet);
		
        if($room == 1) $infos = Room1_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        if($room == 2) $infos = Room2_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        if($room == 3) $infos = Room3_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        $this->game->price = $infos->sum('sum');
        $this->game->save();
        $info = [];
        
        foreach($infos as $bet) {
            $user = $this->findUser($bet->user_id);
            $info[] = [
                'user_id'   => $bet->user_id,
                'avatar'    => $user->avatar,
                'username'  => $user->username,
                'sum'       => $bet->sum,
                'color'     => $bet->color,
                'from'      => $bet->from,
                'to'        => $bet->to,
                'chance'    => $this->getChanceByUser($room, $user->id)
            ];
        }
        
        if($room == 1) {
            $this->redis->publish('jackpot_room1.newBet', json_encode([
                'bets'       => $info,
                'chances'    => $this->getChancesOfGame($room, $this->game->id),
                'line'       => $this->getLine($this->getChancesOfGame($room, $this->game->id)),
                'game'     	 => [
                    'price'	 => $this->game->price
                ]
            ]));
        }
        if($room == 2) {
            $this->redis->publish('jackpot_room2.newBet', json_encode([
                'bets'       => $info,
                'chances'    => $this->getChancesOfGame($room, $this->game->id),
                'line'       => $this->getLine($this->getChancesOfGame($room, $this->game->id)),
                'game'     	 => [
                    'price'	 => $this->game->price
                ]
            ]));
        }
        if($room == 3) {
            $this->redis->publish('jackpot_room3.newBet', json_encode([
                'bets'       => $info,
                'chances'    => $this->getChancesOfGame($room, $this->game->id),
                'line'       => $this->getLine($this->getChancesOfGame($room, $this->game->id)),
                'game'     	 => [
                    'price'	 => $this->game->price
                ]
            ]));
        }
        
        $this->redis->publish('message', json_encode([
            'user'  => $this->user->id,
            'msg'   => 'Ваша ставка одобрена!',
            'icon'  => 'success'
        ]));
        
        if(count($this->getChancesOfGame($room, $this->game->id)) >= 2) {
            if($this->game->status < 1) {
                $this->StartTimer($room);
            }
        }
        
        return ['success' => true];
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
    
    private function getLastTicket($room) {
        if($room == 1) $lastBet = Room1_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->first();
        if($room == 2) $lastBet = Room2_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->first();
        if($room == 3) $lastBet = Room3_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->first();   
        if(is_null($lastBet)) return 1;
        $lastTicket = $lastBet->to+1;
        return $lastTicket;
    }
    
    private function getGameBets($room) {
        if($room == 1) $bets = Room1_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        if($room == 2) $bets = Room2_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        if($room == 3) $bets = Room3_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        
		foreach($bets as $key => $bet) {
            $user = User::where('id', $bet->user_id)->first();
            $bets[$key]->username   = $user->username;
            $bets[$key]->avatar     = $user->avatar;
            $bets[$key]->chances    = $this->getChanceByUser($room, $user->id);
        }
        return $bets;
    }
	
	public static function getLastGame($room) {
		if($room == 1) $game = Room1::orderBy('id', 'desc')->first();
		if($room == 2) $game = Room2::orderBy('id', 'desc')->first();
		if($room == 3) $game = Room3::orderBy('id', 'desc')->first();
		return $game;
	}
    
    private function getLine($chances) {
        $line = [];
        $start = 0;
        for($i = 0; $i < 13; $i++) 
        {
            foreach($chances as $u => $chance) 
            {
                $line[] = [
                    'chance' => $chance,
                    'left' => $start
                ];
                $start += $chance['chance'];
            }
        }
        return $line;
    }

    private function animateTo($user_id, $chances)
    {
        $now = 0; // last %
        foreach($chances as $chance) 
        {
            if($chance['id'] == $user_id) return (900+$now+(50+($chance['chance']/2)));
            $now += $chance['chance'];
        }
        return false;
    }

    private static function getPriceOfGame($room, $gameid, $userid) {
		if($room == 1) $bets = Room1_bets::where('game_id', $gameid)->where('user_id', $userid)->sum('sum');
		if($room == 2) $bets = Room2_bets::where('game_id', $gameid)->where('user_id', $userid)->sum('sum');
		if($room == 3) $bets = Room3_bets::where('game_id', $gameid)->where('user_id', $userid)->sum('sum');
		 
		return $bets;
	}
    
    public static function getChancesOfGame($room, $gameid) {
		if($room == 1) $game = Room1::where('id', $gameid)->first();
		if($room == 2) $game = Room2::where('id', $gameid)->first();
		if($room == 3) $game = Room3::where('id', $gameid)->first();
        $users = [];
        
        if($room == 1) $bets = Room1_bets::where('game_id', $game->id)->orderBy('id', 'desc')->get();
        if($room == 2) $bets = Room2_bets::where('game_id', $game->id)->orderBy('id', 'desc')->get();
        if($room == 3) $bets = Room3_bets::where('game_id', $game->id)->orderBy('id', 'desc')->get();
        foreach($bets as $bet) {
            $find = 0;
            foreach($users as $user) if($user == $bet->user_id) $find++;
            if($find == 0) $users[] = $bet->user_id;
        }
        
        // get chances
        $chances = [];
        foreach($users as $user) {
            $user = User::where('id', $user)->first();
            if($room == 1) $value  = Room1_bets::where('game_id', $game->id)->where('user_id', $user->id)->sum('sum');
            if($room == 2) $value  = Room2_bets::where('game_id', $game->id)->where('user_id', $user->id)->sum('sum');
            if($room == 3) $value  = Room3_bets::where('game_id', $game->id)->where('user_id', $user->id)->sum('sum');
            if($room == 1) $colors = Room1_bets::where('game_id', $game->id)->where('user_id', $user->id)->get();
            if($room == 2) $colors = Room2_bets::where('game_id', $game->id)->where('user_id', $user->id)->get();
            if($room == 3) $colors = Room3_bets::where('game_id', $game->id)->where('user_id', $user->id)->get();
            $chance = ($value/$game->price)*100;
            foreach($colors as $cl) {
                $color = $cl->color;
                $betid = $cl->id;
            }
            $chances[] = [
                'id'        => $user->id,
                'bet_id'    => $betid,
                'username'  => $user->username,
                'avatar'    => $user->avatar,
                'color'     => $color,
                'chance'    => round($chance, 2)
            ];
        }
        
        usort($chances, function($a, $b) {
            return ($a['bet_id']-$b['bet_id']); 
        });
        
        return $chances;
    }
    
    public function getSlider(Request $r) {
        $room = $r->get('room');

        # Поиск победителя
        if($room == 1) $tickets = Room1_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->first();
        if($room == 2) $tickets = Room2_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->first();
        if($room == 3) $tickets = Room3_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->first();
        $tickets = $tickets->to;
        $winTicket = ceil($tickets*$this->game->random_number);
        
        if($room == 1) $bets = Room1_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        if($room == 2) $bets = Room2_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        if($room == 3) $bets = Room3_bets::where('game_id', $this->game->id)->orderBy('id', 'desc')->get();
        foreach($bets as $bet) if(($bet->from <= $winTicket) && ($bet->to >= $winTicket)) $winBet = $bet;
        if(is_null($winBet)) return ['success' => false];
        
        $winner = User::where('id', $winBet->user_id)->first();
        if(is_null($winner)) return ['success' => false];
        
        $users = $this->getChancesOfGame($room, $this->game->id);
        
        if($this->game->winner_id) {
			// Подкрутка. 
			$winner2 = User::where('id', $this->game->winner_id)->first();
            // Поиск билетов юзера
            if($room == 1) $bets = Room1_bets::where('game_id', $this->game->id)->where('user_id', $winner2->id)->get();
            if($room == 2) $bets = Room2_bets::where('game_id', $this->game->id)->where('user_id', $winner2->id)->get();
            if($room == 3) $bets = Room3_bets::where('game_id', $this->game->id)->where('user_id', $winner2->id)->get();
            $bet = $bets[mt_rand(0, count($bets)-1)];
            $winTicket2 = mt_rand($bet->from, $bet->to);
            $number = number_format($winTicket2/$tickets, 20);
            if(floor($number) == 1) {
                $this->game->free = 0;
            } else {
                $winTicket = $winTicket2;
                $winner = $winner2;
            }
            $this->game->random_number = $number;
        }
        
        $this->game->winner_id      = $winner->id;
        $this->game->winner_chance  = $this->getChanceByUser($room, $winner->id);
        $this->game->winner_ticket  = $winTicket;
        $this->game->save();
		
		$this->redis->del('last.ticket_'.$room.'.' . $this->game->id);
        
        return response()->json([
            'winner'        => [
                'username'  => $winner->username,
                'avatar'    => $winner->avatar,
                'chance'    => $this->getChanceByUser($room, $winner->id),
                'ticket'    => $this->game->winner_ticket
            ],
            'ml'            => $this->animateTo($winner->id, $this->getChancesOfGame($room, $this->game->id)),
            'game'          => [
                'number'    => $this->game->random_number,
                'price'     => $this->game->price
            ]
        ]);
    }
    
    private function getChanceByUser($room, $user) {
		if($room == 1) $value = Room1_bets::where('game_id', $this->game->id)->where('user_id', $user)->sum('sum');
		if($room == 2) $value = Room2_bets::where('game_id', $this->game->id)->where('user_id', $user)->sum('sum');
		if($room == 3) $value = Room3_bets::where('game_id', $this->game->id)->where('user_id', $user)->sum('sum');
        $chance = round(($value/$this->game->price)*100);
        return $chance;
    }
    
    private function StartTimer($room) {
        if($room == 1) $time = $this->settings->jackpot_timer_room1;
        if($room == 2) $time = $this->settings->jackpot_timer_room2;
        if($room == 3) $time = $this->settings->jackpot_timer_room3;
        if($room == 1) {
            $this->redis->publish('jackpot_room1.timer', json_encode([
                'time' => $time
            ]));
        }
        if($room == 2) {
            $this->redis->publish('jackpot_room2.timer', json_encode([
                'time' => $time
            ]));
        }
        if($room == 3) {
            $this->redis->publish('jackpot_room3.timer', json_encode([
                'time' => $time
            ]));
        }
    }
    
    public function newGame(Request $r) {
        $room = $r->get('room');
        
        $this->sendMoney($room, $this->game->id);
        
        if($room == 1) {
            Room1::insert([
                'random_number' => '0.'.mt_rand(100000000,999999999).mt_rand(100000000,999999999)
            ]);
            $this->game = Room1::orderBy('id', 'desc')->first();
            $time = floor($this->settings->jackpot_timer_room1);
        }
        if($room == 2) {
            Room2::insert([
                'random_number' => '0.'.mt_rand(100000000,999999999).mt_rand(100000000,999999999)
            ]);
            $this->game = Room2::orderBy('id', 'desc')->first();
            $time = floor($this->settings->jackpot_timer_room2);
        }
        if($room == 3) {
            Room3::insert([
                'random_number' => '0.'.mt_rand(100000000,999999999).mt_rand(100000000,999999999)
            ]);
            $this->game = Room3::orderBy('id', 'desc')->first();
            $time = floor($this->settings->jackpot_timer_room3);
        }
		
		if(count($this->getChancesOfGame($room, $this->game->id)) >= 2) {
			if($this->game->status < 1) {
				$this->StartTimer($room);
			}
		}
         
        return response()->json([
            'id'   	 	 => $this->game->id,
			'user_id'	 => $this->getUserID($room, $this->game->id),
			'game'     	 => [
				'price'	 => $this->game->price
			],
            'time'  	 => $time
        ]);
    }
	
    public function getUserID($room, $gameID)
    {
        if($room == 1) $bet = Room1_bets::where('game_id', $gameID)->orderBy('id', 'desc')->first();
        if($room == 2) $bet = Room2_bets::where('game_id', $gameID)->orderBy('id', 'desc')->first();
        if($room == 3) $bet = Room3_bets::where('game_id', $gameID)->orderBy('id', 'desc')->first();
		if(!$bet) return '0';
        return $bet->user_id;
    }
	
    public function getBets($room, $gameID)
    {
        if($room == 1) {
            $game = Room1::where('id', $gameID)->first();
            $bets = Room1_bets::where('game_id', $gameID)->orderBy('id', 'desc')->get();
        }
        if($room == 2) {
            $game = Room2::where('id', $gameID)->first();
            $bets = Room2_bets::where('game_id', $gameID)->orderBy('id', 'desc')->get();
        }
        if($room == 3) {
            $game = Room3::where('id', $gameID)->first();
            $bets = Room3_bets::where('game_id', $gameID)->orderBy('id', 'desc')->get();
        }
        
        $games = [];
        foreach($bets as $bet) {
            $user = $this->findUser($bet->user_id);
            $games[] = [
                'user_id' => $user->id,
                'avatar' => $user->avatar,
                'chance' => round(($bet->sum/$game->price)*100)
            ];
        }
        return $games;
    }
    
    public function getStatus(Request $r)
    {
        $room = $r->get('room');
        if($room == 1) {
            $time = floor($this->settings->jackpot_timer_room1);
        }
        if($room == 2) {
            $time = floor($this->settings->jackpot_timer_room2);
        }
        if($room == 3) {
            $time = floor($this->settings->jackpot_timer_room3);
        }
        
        return response()->json([
            'id'        => $this->game->id,
            'time'      => $time,
            'status'    => $this->game->status
        ]);
    }
    
	public function sendMoney($room, $id) {
        if($room == 1) {
            $game = Room1::where('id', $id)->first();
            $all_bets = Room1_bets::where('game_id', $id)->sum('sum');
            $w_bet = Room1_bets::where('game_id', $id)->where('user_id', $game->winner_id)->sum('sum');
        }
		if($room == 2) {
            $game = Room2::where('id', $id)->first();
            $all_bets = Room2_bets::where('game_id', $id)->sum('sum');
            $w_bet = Room2_bets::where('game_id', $id)->where('user_id', $game->winner_id)->sum('sum');
        }
		if($room == 3) {
            $game = Room3::where('id', $id)->first();
            $all_bets = Room3_bets::where('game_id', $id)->sum('sum');
            $w_bet = Room3_bets::where('game_id', $id)->where('user_id', $game->winner_id)->sum('sum');
        }
		
        $sum = floor($w_bet + (($all_bets - $w_bet) - ($all_bets - $w_bet)/100*20));
		$user = User::where('id', $game->winner_id)->first();
		
        $profit = parent::makeProfit();
        $this->settings->sys_profit += ($all_bets - $w_bet)/100*20;
        $this->settings->save();

		$user->balance += $sum;
		$user->save();
		
		$this->redis->publish('updateBalance', json_encode([
            'id'    => $user->id,
            'balance' => $user->balance
        ]));
	}
    
    public function gotThis()
    {
        if(($this->game->id/10) == floor($this->game->id/10)) {
            $num = 10;
        } else {
            $num = 10-($this->game->id-(floor($this->game->id/10)*10));   
        }
        
        $num = 1;
        
        $ar = [1];
        for($i = 0; $i < $num-1; $i++) {
            $ar[] = 0;
        }
        shuffle($ar);
        if($ar[mt_rand(0, count($ar)-1)] == 1) return true;
        return false;
    }
    
    public function getTime($room)
    {
        if($room == 1) $time = $this->settings->jackpot_timer_room1;
        if($room == 2) $time = $this->settings->jackpot_timer_room2;
        if($room == 3) $time = $this->settings->jackpot_timer_room3;
        return $time;
    }
    
    public function historyAll()
    {
        $room1 = Room1::where('status', 3)->where('updated_at', '>=', Carbon::today())->orderBy('id', 'desc')->limit(15)->get();
        $room2 = Room2::where('status', 3)->where('updated_at', '>=', Carbon::today())->orderBy('id', 'desc')->limit(10)->get();
        $room3 = Room3::where('status', 3)->where('updated_at', '>=', Carbon::today())->orderBy('id', 'desc')->limit(10)->get();
        
        $all = [];
        $games_1 = [];
        foreach($room1 as $game) {
            $winner = User::where('id', $game->winner_id)->first();
            $games_1[] = [
                'game_id' => $game->id,
                'room' => 1,
                'winner_id' => $game->winner_id,
                'winner_name' => $winner->username,
                'winner_avatar' => $winner->avatar,
                'winner_chance' => $game->winner_chance,
                'winner_ticket' => $game->winner_ticket,
   'winner_ticket1' => $game->winner_ticket+1,
                'data' => Carbon::parse($game->updated_at)->diffForHumans(),
                'price' => $game->price,
                'bets' => $this->getChancesOfGame(1, $game->id)
            ];
        }
        
        $games_2 = [];
        foreach($room2 as $game) {
            $winner = User::where('id', $game->winner_id)->first();
            $games_2[] = [
                'game_id' => $game->id,
                'room' => 2,
                'winner_id' => $game->winner_id,
                'winner_name' => $winner->username,
                'winner_avatar' => $winner->avatar,
                'winner_chance' => $game->winner_chance,
                'winner_ticket' => $game->winner_ticket,
                'data' => Carbon::parse($game->updated_at)->diffForHumans(),
                'price' => $game->price,
                'bets' => $this->getChancesOfGame(2, $game->id)
            ];
        }
        
        $games_3 = [];
        foreach($room3 as $game) {
            $winner = User::where('id', $game->winner_id)->first();
            $games_3[] = [
                'game_id' => $game->id,
                'room' => 3,
                'winner_id' => $game->winner_id,
                'winner_name' => $winner->username,
                'winner_avatar' => $winner->avatar,
                'winner_chance' => $game->winner_chance,
                'winner_ticket' => $game->winner_ticket,
                'data' => Carbon::parse($game->updated_at)->diffForHumans(),
                'price' => $game->price,
                'bets' => $this->getChancesOfGame(3, $game->id)
            ];
        }
        //$all = array_merge($games_1, $games_2, $games_3);

        return view('pages.history', compact('games_1', 'games_2', 'games_3'));
    }
    
    public function historyMy()
    {
        $room1 = Room1::where('status', 3)->where('winner_id', $this->user->id)->where('updated_at', '>=', Carbon::today())->orderBy('id', 'desc')->get();
        $room2 = Room2::where('status', 3)->where('winner_id', $this->user->id)->where('updated_at', '>=', Carbon::today())->orderBy('id', 'desc')->get();
        $room3 = Room3::where('status', 3)->where('winner_id', $this->user->id)->where('updated_at', '>=', Carbon::today())->orderBy('id', 'desc')->get();
        
        $all = [];
        $games_1 = [];
        foreach($room1 as $game) {
            $winner = User::where('id', $game->winner_id)->first();
            $games_1[] = [
                'game_id' => $game->id,
                'room' => 1,
                'winner_id' => $game->winner_id,
                'winner_name' => $winner->username,
                'winner_avatar' => $winner->avatar,
                'winner_chance' => $game->winner_chance,
        'winner_ticket1' => $game->winner_ticket+1,
                'winner_ticket' => $game->winner_ticket,
                'data' => Carbon::parse($game->updated_at)->diffForHumans(),
                'price' => $game->price,
                'bets' => $this->getChancesOfGame(1, $game->id)
            ];
        }
        
        $games_2 = [];
        foreach($room2 as $game) {
            $winner = User::where('id', $game->winner_id)->first();
            $games_2[] = [
                'game_id' => $game->id,
                'room' => 2,
                'winner_id' => $game->winner_id,
                'winner_name' => $winner->username,
                'winner_avatar' => $winner->avatar,
                'winner_chance' => $game->winner_chance,
                'winner_ticket' => $game->winner_ticket,
                'data' => Carbon::parse($game->updated_at)->diffForHumans(),
                'price' => $game->price,
                'bets' => $this->getChancesOfGame(2, $game->id)
            ];
        }
        
        $games_3 = [];
        foreach($room3 as $game) {
            $winner = User::where('id', $game->winner_id)->first();
            $games_3[] = [
                'game_id' => $game->id,
                'room' => 3,
                'winner_id' => $game->winner_id,
                'winner_name' => $winner->username,
                'winner_avatar' => $winner->avatar,
                'winner_chance' => $game->winner_chance,
                'winner_ticket' => $game->winner_ticket,
                'data' => Carbon::parse($game->updated_at)->diffForHumans(),
                'price' => $game->price,
                'bets' => $this->getChancesOfGame(3, $game->id)
            ];
        }
        //$all = array_merge($games_1, $games_2, $games_3);

        return view('pages.history', compact('games_1', 'games_2', 'games_3'));
    }
	
    private function findUser($id)
    {
        $user = User::where('id', $id)->first();
        return $user;
    }
    
    public function setStatus(Request $r) {
		$room = $r->get('room');
		$status = $r->get('status');
		if($room == 1) {
			$this->game->status = $status;
			$this->game->save();
		}
		if($room == 2) {
			$this->game->status = $status;
			$this->game->save();
		}
		if($room == 3) {
			$this->game->status = $status;
			$this->game->save();
		}
		
		return [
			'msg'       => 'Статус изменен на '.$status.' в комнате #'.$room.'!',
			'type'      => 'success'
		];
	}
    
    public function gotRoulette(Request $r) {
        $room = $r->get('room');
		$gameid = $this->getLastGame($room);
		$userid = $r->get('user_id');
		$user = User::where('id', $userid)->first();
		
		if(!$gameid) return [
			'msg'       => 'Не удалось получить номер игры!',
			'type'      => 'error'
		];
		
		if(!$userid) return [
			'msg'       => 'Не удалось получить ид игрока!',
			'type'      => 'error'
		];
		
        if($room == 1) {
            Room1::where('id', $gameid->id)->update([
                'winner_id' => $userid
            ]);
        }
        if($room == 2) {
            Room2::where('id', $gameid->id)->update([
                'winner_id' => $userid
            ]);
        }
        if($room == 3) {
            Room3::where('id', $gameid->id)->update([
                'winner_id' => $userid
            ]);
        }
		
		return [
			'msg'       => 'Вы подкрутили игроку '.$user->username.' в комнате #'.$room.'!',
			'type'      => 'success'
		];
	}
}