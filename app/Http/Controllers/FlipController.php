<?php

namespace App\Http\Controllers;

use App\User;
use App\Settings;
use App\CoinFlip;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Requests;
use Redis;
use Storage;
use DB;

class FlipController  extends Controller {
	
	public function __construct() {
        parent::__construct();
    }
	
	public function index() {	
		$rooms = CoinFlip::where('status', 0)->get();
		$ended = CoinFlip::where('status', 1)->orderBy('id', 'desc')->limit(5)->get();
		return view('pages.flip.index', compact('rooms', 'ended'));
	}
	
	public function joinRoom(Request $r)
	{
		if (\Cache::has('bet.user.' . $this->user->id)) return 0;
        \Cache::put('bet.user.' . $this->user->id, '', 0.05);
		$room = CoinFlip::where('id', $r->get('id'))->first();
        $coins = $room->coins_user1;
		if($coins > $this->user->balance) return response()->json(['type' => 'error', 'msg' => 'Не достаточно средств!']);
		if($room->status == 1) return response()->json(['type' => 'error', 'msg' => 'Игра #'.$room->id.' уже началась!']);
		if(!$coins) return response()->json(['type' => 'error', 'msg' => 'Вы забыли указать сумму ставки!']);
		if($coins <= 0) return response()->json(['type' => 'error', 'msg' => 'Минимальная ставка 1 монета!']);
		if($this->user->balance <= 0) return response()->json(['type' => 'error', 'msg' => 'Вам не хватает монет для совершения ставки!']);
		if($room->user1 == $this->user->id) return response()->json(['type' => 'error', 'msg' => 'Вы не можете учавствовать в своей игре!']);
		
        $getIp = User::where('id', $room->user1)->first();
        if(isset($this->user->ip)) {
            if($getIp->ip == $this->user->ip) {
                return response()->json(['type' => 'error', 'msg' => 'Вы уже сделали ставку с другого аккаунта!']);
            }
        }
        
		$this->user->balance -= $coins;
		$this->user->save();
        
        $this->redis->publish('updateBalance', json_encode([
            'id'    => $this->user->id,
            'balance' => $this->user->balance
        ]));
		
        $room->coins_user2 = $coins;
		$room->user2 = $this->user->id;
		$room->price += $coins;
		$user_rand1 = ['1', '4', '5', '8', '9'];
		$user_rand2 = ['2', '3', '6', '7', '10'];
        $winner = mt_rand(1, 10);
        if($winner == 1 || $winner == 4 || $winner == 5 || $winner == 8 || $winner == 9) {
          $winner = User::where('id', $room->user1)->first();
       } else {
          $winner = User::where('id', $room->user2)->first();
        }
		$random = mt_rand(0, 1000);
		if ($random > 500) {
			$winner = User::where('id', $room->user1)->first();
		} else {
			$winner = User::where('id', $room->user2)->first();
		}
        $room->winner_id = $winner->id;
        $room->status = 1;
        $room->save();
		
		$user1 = User::where('id', $room->user1)->first();
		$user2 = User::where('id', $room->user2)->first();
		if($winner->id == $user1->id) {
			$loser = User::where('id', $user2->id)->first();
		} else {
			$loser = User::where('id', $user1->id)->first();
		}
		$user_win = User::where('id', $winner->id)->first();
		
		$returnValues = [
			'status' 	=> 'success',
            'user1'     => [
				'username' 	=> $user1->username,
				'avatar' 	=> $user1->avatar
			],
            'user2'     => [
				'username' 	=> $user2->username,
				'avatar' 	=> $user2->avatar
			],
            'winner'    => [
				'username' 	=> $user_win->username,
				'avatar' 	=> $user_win->avatar
			],
            'loser'    => [
				'username' 	=> $loser->username,
				'avatar' 	=> $loser->avatar
			],
            'game'      => [
                'id'        => $room->id,
                'price'     => $room->price
            ]
        ];
		
        $sum = floor($room->coins_user1 + (($room->price - $room->coins_user1) - ($room->price - $room->coins_user1)/100*20));
        
        $winner->balance += $sum;
        $winner->save();
        $profit = parent::makeProfit();
        $this->settings->sys_profit += ($room->price - $room->coins_user1)/100*20;
        $this->settings->save();
		
		$this->redis->publish('end.flip', json_encode($returnValues));
        
        $this->redis->publish('updateBalanceAfter', json_encode([
            'id'    => $winner->id,
            'balance' => $winner->balance
        ]));
		
		$this->redis->publish('message', json_encode([
			'user'  => $this->user->id,
			'msg'   => 'Вы вошли в игру!',
			'icon'  => 'success'
		]));
		return response()->json(['success' => true]);
	}

    public function createRoom(Request $r)
    {
		if (\Cache::has('bet.user.' . $this->user->id)) return 0;
        \Cache::put('bet.user.' . $this->user->id, '', 0.05);
		$count = CoinFlip::where('user1', $this->user->id)->where('status', 0)->count();
		if($r->get('value') > $this->user->balance) return response()->json(['type' => 'error', 'msg' => 'Не достаточно средств!']);
		if($r->get('value') < 1) return response()->json(['type' => 'error', 'msg' => 'Минимальная сумма ставки 10 PT']);
		if($count >= 3) return response()->json(['type' => 'error', 'msg' => 'Вы создали максимальное количество комнат!']);
		if(!$r->get('value')) return response()->json(['type' => 'error', 'msg' => 'Вы забыли указать сумму ставки!']);
		if($r->get('value') <= 0) return response()->json(['type' => 'error', 'msg' => 'Минимальная ставка 1 PT!']);
		if($this->user->balance <= 0) return response()->json(['type' => 'error', 'msg' => 'Вам не хватает PT для совершения ставки!']);
		$user = User::where('id', $this->user->id)->first();
		$rand_number = "0." . mt_rand(100000000, 999999999) . mt_rand(100000000, 999999999);
		$room = new CoinFlip();
		$room->hash = md5($rand_number);
        $coins = floor($r->get('value'));
        $room->coins_user1 = $coins;
		$room->rand_number = $rand_number;
		$room->user1 = $this->user->id;
        $room->price = $coins;
        $room->save();
		
		$this->user->balance -= $coins;
		$this->user->save();
		
		$this->redis->publish('new.flip', json_encode(['status' => 'success', 'id' => $room->id, 'html' => view('pages.flip.includes.room', compact('room'))->render()]));
		
		$this->redis->publish('updateBalance', json_encode([
            'id'    => $this->user->id,
            'balance' => $this->user->balance
        ]));
		
		return response()->json(['type' => 'success', 'msg' => 'Игра создана!']);
    }
}