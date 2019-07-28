<?php

namespace App\Http\Controllers;

use App\Crash;
use App\CrashBets;
use App\User;
use Illuminate\Http\Request;
use Auth;
use Carbon\Carbon;

class CrashController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->game = Crash::orderBy('id', 'desc')->first();
    }

    public function index()
    {
        $bet = $this->getBet();
        $history = $this->getHistory();
        $bets = $this->getBets();
        return view('pages.crash', compact('bet', 'history', 'bets'));
    }

    public function h2()
    {
        return dd($this->settings->crash_profit);
    }

    private function h()
    {
        if($this->TrueFalse(10)) return '1.00';
        if($this->TrueFalse(40)) 
        {
            if($this->TrueFalse(35)) return mt_rand(2,3).'.'.mt_rand(1,9).mt_rand(1,9);
            return mt_rand(1,2).'.'.mt_rand(1,9).mt_rand(1,9);
        }
        if($this->TrueFalse(20)) return mt_rand(3,5).'.'.mt_rand(1,9).mt_rand(1,9);
        if($this->TrueFalse(3)) return mt_rand(1,200).'.'.mt_rand(1,9).mt_rand(1,9);
        return '1.'.mt_rand(0,9).mt_rand(0,9);
    }

    /*public function h()
    {
        $start = floor($this->game->id/$this->settings->crash_pgames)*$this->settings->crash_pgames;
        $profit = Crash::where('status', 3)->where('id', '>=', $start)->sum('finaly');

        $bets = CrashBets::where('round_id', $this->game->id)->sum('price');

        if($bets == 0) 
        {
            if($this->TrueFalse(96)) 
            {
                if($this->TrueFalse(20)) return '1.00';
                if($this->TrueFalse(80)) return mt_rand(1, 3).'.'.mt_rand(0, 99); else return mt_rand(3, 15).'.'.mt_rand(11,99);
            } else {
                return mt_rand(1, 1000).'.'.mt_rand(11,99);
            }
        }

        // Если профит меньше, чем установленный
        if($profit < $this->settings->crash_profit)
        {
            $check = Crash::where('multiplier', '<', 1.3)->where('id', '>', $this->game->id-3)->first();
            if(is_null($check) && $this->TrueFalse(80)) return '1.00'; 
            if($bets > $this->getDifference($profit, $this->settings->crash_profit)/($this->settings->crash_pgames/2)) return '1.00';
            if($this->TrueFalse(10)) return mt_rand(2,5).'.'.mt_rand(11,99);
            if($this->TrueFalse(80)) return '1.00'; else return mt_rand(1,3).'.'.mt_rand(11,99);
        } else {
            if(($profit/2) > $this->settings->crash_profit && $this->TrueFalse(5)) return mt_rand(1, floor(($profit/2)/$bets)).'.'.mt_rand(11, 99);
            return mt_rand(1,3).'.'.mt_rand(0, 99);
        }

        // return dd($this->TrueFalse(20));


        //return '1.00';
        return $this->getDifference(); 

    }*/

    private function TrueFalse($p)
    {
        $list = [];
        for($i = 0; $i < 10000-($p*100); $i++) $list[] = false;
        for($i = 0; $i < $p*100; $i++) $list[] = true;
        shuffle($list);
        return $list[mt_rand(0, count($list)-1)];
    }

    private function getDifference($n, $nn)
    {
        if($n > $nn) return $nn-$n;
        if($n < 0)
        {
            return ((abs($n)/abs($nn))*abs($nn))+abs($nn);
        } else {
            return (1-($n/$nn))*$nn;
        }
    }

    public function test()
    {
        $price = mt_rand(1, 300);
        $multiplier = floatval(mt_rand(1,3).'.'.mt_rand(1,99));
        CrashBets::create([
            'user_id' => 7,
            'round_id' => $this->game->id,
            'price' => $price,
            'status' => mt_rand(0,1),
            'withdraw' => $multiplier,
            'won' => floor($price*$multiplier)
        ]);
        $this->game->price += $price;
        $this->game->save();
    }

    public function getBets()
    {
        $bets = CrashBets::where('round_id', $this->game->id)->orderBy('won', 'desc')->get();
        $list = [];
        foreach($bets as $bet)
        {
            $user = User::where('id', $bet->user_id)->first();
            if(!is_null($user))
            {
                $list[] = [
                    'user' => [
                        'username' => $user->username,
                        'avatar' => $user->avatar
                    ],
                    'price' => $bet->price,
                    'status' => $bet->status,
                    'withdraw' => [
                        'color' => $this->getColor($bet->withdraw),
                        'm' => $bet->withdraw
                    ],
                    'won' => $bet->won
                ];
            }
        }

        return $list;
    }

    public function updatePage()
    {
        $this->redis->publish('crash', json_encode([
            'type' => 'bets',
            'bets' => $this->getBets(),
            'bc' => count(CrashBets::where('round_id', $this->game->id)->get()),
            'bs' => CrashBets::where('round_id', $this->game->id)->sum('price')
        ]));
    }

    public function addBet(Request $r)
    {
        /*if($this->user->id != 1) return [
            'success' => false,
            'msg' => 'На сайте ведутся технические работы!'
        ];*/

        if($this->getBet()) return [
            'success' => false,
            'msg' => 'Вы уже сделали ставку в этом раунде!'
        ];

        if($this->game->status > 1) return [
            'success' => false,
            'msg' => 'Ставки в этом раунде закрыты!'
        ];

        if($this->user->balance < floatval($r->get('bet'))) return [
            'success' => false,
            'msg' => 'Недостаточно баланса!'
        ];

        if(floatval($r->get('bet')) < $this->settings->crash_min_bet) return [
            'success' => false,
            'msg' => 'Минимальная сумма ставки - '.$this->settings->crash_min_bet
        ];

        if(floatval($r->get('bet')) > $this->settings->crash_max_bet) return [
            'success' => false,
            'msg' => 'Максимальная сумма ставки - '.$this->settings->crash_max_bet
        ];

        $this->user->balance -= floatval($r->get('bet'));
        $this->user->save();

        $bet = CrashBets::create([
            'user_id' => $this->user->id,
            'round_id' => $this->game->id,
            'price' => floatval($r->get('bet')),
            'won' => floatval($r->get('bet'))
        ]);

        $this->game->price += $bet->price;
        $this->game->finaly += $bet->price;
        $this->game->save();

        $this->redis->publish('updateBalance', json_encode([
            'id' => $this->user->id,
            'balance' => $this->user->balance
        ]));

        $this->updatePage();

        return [
            'success' => true,
            'bet' => floatval($r->get('bet'))
        ];
    }

    public function getSlider()
    {
        $this->game->multiplier = $this->h();
        $this->game->save(); 

        return [
            'multiplier' => $this->game->multiplier
        ];
    }

    public function withdraw(Request $r)
    {
        $user = User::where('persona_hash', $r->get('hash'))->first();
        if(is_null($user)) return [
            'success' => false,
            'msg' => 'Не удалось найти вас в базе данных!'
        ];

        if($this->game->status != 2) return [
            'success' => false,
            'msg' => 'Вы не можете вывести ставку в данный момент!'
        ];

        $bet = CrashBets::where('user_id', $user->id)->where('round_id', $this->game->id)->first();
        if(is_null($bet)) return [
            'success' => false,
            'msg' => 'Не удалось найти вашу ставку!'
        ];

        if($bet->status == 1) return [
            'success' => false,
            'msg' => 'Вы уже вывели вашу ставку!'
        ];

        $bet->withdraw = $r->get('multiplier');
        $bet->won = floor($bet->price*$r->get('multiplier'));
        $bet->status = 1;
        $bet->save();

        $this->game->finaly -= $bet->won;
        $this->game->save();

        if($this->game->finaly < ($this->game->price*($this->settings->crash_profit/100)) && $this->game->finaly > 0)
        {
            $this->redis->publish('crash.stop', 'Ты шо, ебанутый? Что ты там делаешь?');
        }

        $user->balance += $bet->won;
        $user->save();

        $this->redis->publish('updateBalance', json_encode([
            'id' => $user->id,
            'balance' => $user->balance
        ]));

        $this->updatePage();

        return [
            'success' => true,
            'msg' => 'Ваш выигрыш : '.$bet->won,
            'won' => $bet->won
        ];
    }

    public function newGame()
    {
        $this->game->finaly = $this->game->price;
        $this->game->finaly -= floatval(CrashBets::where('status', 1)->where('round_id', $this->game->id)->sum('won'));
        $this->game->save();

        $this->game = Crash::create();

        return [
            'id' => $this->game->id,
            'time' => $this->settings->crash_timer
        ];
    }

    public function getHistory()
    {
        $games = Crash::select('multiplier as m')->where('status', 3)->orderBy('id', 'desc')->limit(18)->get();
        $list = [];
        foreach($games as $game)
        {
            $list[] = [
                'm' => number_format($game->m,2),
                'color' => $this->getColor($game->m)
            ];
        }
        return $list;
    }

    private function getColor($float)
    {
        if($float > 6.49) return '#037cf3';
        if($float > 4.49) return '#4163f3';
        if($float > 2.99) return '#9f4afd';
        if($float > 1.99) return '#ed14c9';
        return '#f7b821';
    }

    public function updateStatus(Request $r)
    {
        $this->game->status = $r->get('status');
        if($r->get('multiplier') !== false) $this->game->multiplier = $r->get('multiplier');
        $this->game->save();

        if($this->game->status == 3)
        {
            $this->redis->publish('crash', json_encode([
                'type' => 'history',
                'history' => $this->getHistory()
            ]));
        }

        return ['success' => true];
    } 

    public function getGame()
    {
        return [
            'id' => $this->game->id,
            'time' => $this->settings->crash_timer, // timer time
            'status' => $this->game->status
        ];
    }

    public function registerUser()
    {
        if(Auth::user()) 
        {
            $this->user->persona_hash = $this->regHash();
            $this->user->save();
        }
        $bet = $this->getBet();
        return [
            'hash' => (Auth::user()) ? $this->user->persona_hash : false,
            'bet' => (Auth::user()) ? ($bet === false) ? false : true : false,
            'balance' => (Auth::user()) ? $this->user->balance : false,
            'bet_value' => ($bet) ? $bet->price : false
        ];
    }

    public function getBet()
    {
        if(!Auth::user()) return false;
        $bet = CrashBets::where('user_id', $this->user->id)->where('round_id', $this->game->id)->first();
        if(is_null($bet)) return false;
        return $bet;
    }

    public function regHash()
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz1234567890';
        $string = '';
        for($i = 0; $i < 100; $i++) $string .= $chars[mt_rand(0,mb_strlen($chars)-1)];
        if(is_null(User::where('persona_hash', hash('sha256', $string))->first())) return hash('sha256', $string);
        return $this->regHash();
    }
}