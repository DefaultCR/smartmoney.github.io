<?php

// Coded by l1ght
// https://vk.com/l1ghtcs
// 20.08.2017

namespace App\Http\Controllers;

use Auth;
use App\User;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Cube;

class CubeGameController extends Controller
{
	const MIN_BET = 1; # Мин сумма ставки
	const COMISSION = 10; # Комиссия для обычного юзера
	const COMISSION_NICK = 5; # Комиссия для юзера с сайтом в нике
	const SITENAME = 'https://vk.com/l1ghtcs'; # Название сайта
	
	public function CubeGame() {
		parent::setTitle('CUBE GAME | ');
		$cubegamestart = Cube::where('status', 0)->orderBy('id', 'desc')->get();
		$cubegamefinish = Cube::where('status', 1)->orderBy('updated_at', 'desc')->take(10)->get();
		return view('pages.cube' , compact('cubegamestart', 'cubegamefinish'));
	}

	public function NewCubeGame(Request $request) {
		$rand1 = rand(1,6);
		$rand2 = rand(1,6);
		$rand3 = rand(1,6);
		$rand4 = rand(1,6);
		$random = $rand1 + $rand2 + $rand3 + $rand4;
		$number = round($request->get('number1'));
		$stavka = round($request->get('stavka'), 2);

		if(empty($number) || empty($stavka)) {
			return response()->json(['message' => 'Заполните каждое поле!', 'type' => 'error']);
		} elseif(!is_numeric($number) || !is_numeric($stavka)) {
			return response()->json(['message' => 'Недопустимые символы!', 'type' => 'error']);
		} elseif($this->user->money < $stavka) {
			return response()->json(['message' => 'Недостаточно средств!', 'type' => 'error']);
		} elseif($stavka < self::MIN_BET) {
			return response()->json(['message' => 'Минимальная сумма - '.self::MIN_BET.' руб.', 'type' => 'error']);
		} elseif($number > 24) {
			return response()->json(['message' => 'Максимальное число - 24.', 'type' => 'error']);
		} elseif($number < 4) {
			return response()->json(['message' => 'Минимальное число - 4.', 'type' => 'error']);
		}

		$this->user->money -= $stavka;
		$this->user->save(); // Отнимаем сумму ставки у первого юзера
		
		$game = new Cube();
		$game->win_number = $random;
		$game->user_1 = $this->user->username;
		$game->user_1_id = $this->user->id;
		$game->stavka = $stavka;
		$game->user_1_number = $number;
		$game->status = 0;
		$game->user_1_avatar = $this->user->avatar;
		$game->rand1 = $rand1;
		$game->rand2 = $rand2;
		$game->rand3 = $rand3;
		$game->rand4 = $rand4;
		$game->save();

		$returnValue = [
			'id' => $game->id,
			'user_1' => $this->user->username,
			'stavka' => $stavka,
			'steamid64' => $this->user->steamid64,
			'user_1_avatar' => $this->user->avatar
		];

		$this->redis->publish('createLobby', json_encode($returnValue));

		return response()->json(['message' => 'Игра создана!', 'type' => 'success']);
	}
	
	public function JoinCubeGame(Request $request) {
		
		$id = $request->get('cube_id');
		$number2 = $request->get('number2');
		$number2 = round($number2);
		$thisgame = Cube::where('id', $id)->first();
		$user = User::where('id', $thisgame->user_1_id)->first();
		$site_comission = ($thisgame->stavka * 2) / 100 * self::COMISSION; // Комиссия для обычного юзера
		
		if(empty($number2)) {
			return response()->json(['message' => 'Введите число!', 'type' => 'error']);
		} elseif(!is_numeric($number2)) {
			return response()->json(['message' => 'Недопустимые символы!', 'type' => 'error']);
		} elseif(is_null($thisgame)) {
			return response()->json(['message' => 'Игра не найдена!', 'type' => 'error']);
		} elseif($thisgame->user_1_id == $this->user->id) {
			return response()->json(['message' => 'Это ваша игра!', 'type' => 'error']);
		} elseif($this->user->money < $thisgame->stavka) {
			return response()->json(['message' => 'Недостаточно средств!', 'type' => 'error']);
		} elseif($thisgame->status != 0) {
			return response()->json(['message' => 'Игра уже началась!', 'type' => 'error']);
		} elseif($number2 > 24) {
			return response()->json(['message' => 'Максимальное число - 24.', 'type' => 'error']);
		} elseif($number2 < 4) {
			return response()->json(['message' => 'Минимальное число - 4.', 'type' => 'error']);
		}

		if ($thisgame->user_1_number == $number2) { // Ничья
			$this->user->money += $thisgame->stavka;
			$this->user->save(); // Возвращаем сумму ставки первому юзеру
			Cube::where('id', $id)->update(['status' => 1,
				'user_2' => $this->user->username,
				'user_2_number' => $number2,
				'user_2_id' => $this->user->id,
				'user_2_avatar' => $this->user->avatar,
				'standoff' => 1
			]);
			$returnvalue = [
				'cube_id_enter' => $request->get('cube_id'),
				'winner_id' => 0,
				'stavka' => $thisgame->stavka,
				'user_1' => $thisgame->user_1,
				'user_2' => $this->user->username,
				'user_1_id' => $thisgame->user_1_id,
				'user_2_id' => $this->user->id,
				'user_1_number' => $thisgame->user_1_number,
				'user_2_number' => $number2,
				'user_1_avatar' => $thisgame->user_1_avatar,
				'user_2_avatar' => $this->user->avatar,
				'user_1_steamid64' => $user-> steamid64,
				'user_2_steamid64' => $this->user->steamid64,
				'rand1' => $thisgame->rand1,
				'rand2' => $thisgame->rand2,
				'rand3' => $thisgame->rand3,
				'rand4' => $thisgame->rand4,
				'standoff' => 1
			];
			$this->redis->publish('enterLobby', json_encode($returnvalue));
			
			return response()->json(['message' => 'Игра началась.', 'type' => 'success']);
		}
	
		if (($thisgame->win_number - $thisgame->user_1_number) == ($number2 - $thisgame->win_number)) { // Ничья
			$this->user->money += $thisgame->stavka;
			$this->user->save(); // Возвращаем сумму ставки первому юзеру
			Cube::where('id', $id)->update(['status' => 1,
				'user_2' => $this->user->username,
				'user_2_number' => $number2,
				'user_2_id' => $this->user->id,
				'user_2_avatar' => $this->user->avatar,
				'standoff' => 1
			]);
			$returnvalue = [
				'cube_id_enter' => $request->get('cube_id'),
				'winner_id' => 0,
				'stavka' => $thisgame->stavka,
				'user_1' => $thisgame->user_1,
				'user_2' => $this->user->username,
				'user_1_id' => $thisgame->user_1_id,
				'user_2_id' => $this->user->id,
				'user_1_number' => $thisgame->user_1_number,
				'user_2_number' => $number2,
				'user_1_avatar' => $thisgame->user_1_avatar,
				'user_2_avatar' => $this->user->avatar,
				'user_1_steamid64' => $user-> steamid64,
				'user_2_steamid64' => $this->user->steamid64,
				'rand1' => $thisgame->rand1,
				'rand2' => $thisgame->rand2,
				'rand3' => $thisgame->rand3,
				'rand4' => $thisgame->rand4,
				'standoff' => 1
			];
			$this->redis->publish('enterLobby', json_encode($returnvalue));
			
			return response()->json(['message' => 'Игра началась.', 'type' => 'success']);
		}

    if ($thisgame->win_number > $thisgame->user_1_number) { // Получаем дистанцию между числом первого юзера и числом игры
    	$distance1 = ($thisgame->win_number - $thisgame->user_1_number - 1);
    } else {
    	$distance1 = ($thisgame->user_1_number - $thisgame->win_number - 1);
    }

	if ($thisgame->win_number > $number2) { // Получаем дистанцию между числом второго юзера и числом игры
		$distance2 = ($thisgame->win_number - $number2 - 1);
	} else {
		$distance2 = ($number2 - $thisgame->win_number - 1);
	}

    if($distance1 < $distance2) { // Выигрывает первый юзер
    	$checkvip = strripos($thisgame->user_1, self::SITENAME);
		if ($checkvip !== false) $site_comission = ($thisgame->stavka*2)/100*self::COMISSION_NICK; // Комиссия для юзера с сайтом в нике
	    if ($thisgame->user_1_number == $thisgame->win_number) { // Не вычитаем комиссию если юзер угадал число
	    	$win_money = ($thisgame->stavka*2);
	    } else {
	    	$win_money = ($thisgame->stavka*2)-$site_comission;
	    }
		
		$this->user->money -= $thisgame->stavka;
		$this->user->save(); // Отнимаем сумму ставки у второго юзера
		
		User::where('id', $thisgame->user_1_id)->update(['money' => $user->money + $win_money]);
		
		Cube::where('id', $id)->update(['status' => 1,
			'user_2' => $this->user->username,
			'user_2_number' => $number2,
			'user_2_id' => $this->user->id,
			'user_2_avatar' => $this->user->avatar,
			'winner' => $thisgame->user_1,
			'winner_id' => $thisgame->user_1_id
		]);
		$returnvalue = [
				'cube_id_enter' => $request->get('cube_id'),
				'winner_id' => $thisgame->user_1_id,
				'winner_steamid64' => $user->steamid64,
				'stavka' => $thisgame->stavka,
				'user_1' => $thisgame->user_1,
				'user_2' => $this->user->username,
				'user_1_id' => $thisgame->user_1_id,
				'user_2_id' => $this->user->id,
				'user_1_number' => $thisgame->user_1_number,
				'user_2_number' => $number2,
				'user_1_avatar' => $thisgame->user_1_avatar,
				'user_2_avatar' => $this->user->avatar,
				'user_1_steamid64' => $user-> steamid64,
				'user_2_steamid64' => $this->user->steamid64,
				'rand1' => $thisgame->rand1,
				'rand2' => $thisgame->rand2,
				'rand3' => $thisgame->rand3,
				'rand4' => $thisgame->rand4,
				'win_money' => $win_money,
				'standoff' => 0
		];
		$this->redis->publish('enterLobby', json_encode($returnvalue));
		
		return response()->json(['message' => 'Игра началась.', 'type' => 'success']);
	}
	else if ($distance1 > $distance2) { // Выигрывает второй юзер
		$checkvip = strripos($this->user->username, self::SITENAME);
		if ($checkvip !== false) $site_comission = ($thisgame->stavka*2)/100*self::COMISSION_NICK; // Комиссия для юзера с сайтом в нике
	    if ($number2 == $thisgame->win_number) { // Не вычитаем комиссию если юзер угадал число
	    	$win_money = $thisgame->stavka;
	    } else {
	    	$win_money = $thisgame->stavka - $site_comission;
	    }
		
		$this->user->money += $win_money;
		$this->user->save();
		
		Cube::where('id', $id)->update(['status' => 1,
			'user_2' => $this->user->username,
			'user_2_number' => $number2,
			'user_2_id' => $this->user->id,
			'user_2_avatar' => $this->user->avatar,
			'winner' => $this->user->username,
			'winner_id' => $this->user->id
		]);
	    $returnvalue = [
				'cube_id_enter' => $request->get('cube_id'),
				'winner_id' => $this->user->id,
				'winner_steamid64' => $this->user->steamid64,
				'stavka' => $thisgame->stavka,
				'user_1' => $thisgame->user_1,
				'user_2' => $this->user->username,
				'user_1_id' => $thisgame->user_1_id,
				'user_2_id' => $this->user->id,
				'user_1_number' => $thisgame->user_1_number,
				'user_2_number' => $number2,
				'user_1_avatar' => $thisgame->user_1_avatar,
				'user_2_avatar' => $this->user->avatar,
				'user_1_steamid64' => $user-> steamid64,
				'user_2_steamid64' => $this->user->steamid64,
				'rand1' => $thisgame->rand1,
				'rand2' => $thisgame->rand2,
				'rand3' => $thisgame->rand3,
				'rand4' => $thisgame->rand4,
				'win_money' => $win_money,
				'standoff' => 0
		];
	    $this->redis->publish('enterLobby', json_encode($returnvalue));
		
		return response()->json(['message' => 'Игра началась.', 'type' => 'success']);
	}


}

public function CloseCubeGame(Request $request) {

	if (Auth::check()) {
		$user = User::where('id', $this->user->id)->first();
		$money = $user->money;
		$id = $request->get('cube_id_close');
		$game = Cube::where('id', $id)->first();

		if (is_null($game)) {
			return response()->json(['message' => 'Игра не найдена!', 'type' => 'error']);
		} elseif (is_null($user)) {
			return response()->json(['message' => 'Пользователь не найден!', 'type' => 'error']);
		} elseif ($game->user_1_id !== $user->id) {
			return response()->json(['message' => 'Это не Ваша игра!', 'type' => 'error']);
		} elseif ($game->status != 0) {
			return response()->json(['message' => 'Игра уже началась!', 'type' => 'error']);
		} else {

			User::where('id', $user->id)->update(['money' => $money + $game->stavka]);
			Cube::where('id', $id)->update(['status' => 2]);
			
			$returnValue = [
				'cube_id_close' => $id
			];
			$this->redis->publish('closeLobby', json_encode($returnValue));

			return response()->json(['message' => 'Вы успешно закрыли игру!', 'type' => 'success']);
		}
	} else {
		return response()->json(['message' => 'Вы не авторизованы!', 'type' => 'error']);
	}
}
}