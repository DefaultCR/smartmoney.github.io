<?php namespace App\Http\Controllers;

use App\Profit;
use App\User;
use App\Crash;
use App\Bonus;
use App\Withdraw;
use App\Promocode;
use App\SuccessPay;
use App\Settings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class AdminController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $gameid_r1 = JackpotController::getLastGame(1);
		$gameid_r2 = JackpotController::getLastGame(2);
		$gameid_r3 = JackpotController::getLastGame(3);
        view()->share('chances_room1', JackpotController::getChancesOfGame(1, $gameid_r1->id));
		view()->share('chances_room2', JackpotController::getChancesOfGame(2, $gameid_r2->id));
		view()->share('chances_room3', JackpotController::getChancesOfGame(3, $gameid_r3->id));
    }
    
    public function botOn()
    {
        putenv("HOME=/var/www/html/storage/app/");
        $start_bot = new Process('pm2 start /var/www/html/storage/bot/app.js');
        $start_bot->run();
        $start_bot->start();

    	return redirect()->route('admin')->with('success', 'Бот включен!');
    }
    
    public function botOff()
    {
        putenv("HOME=/var/www/html/storage/app/");
        $stop_bot = new Process('pm2 stop /var/www/html/storage/bot/app.js'); 
        $dell_proc = new Process('pm2 delete app'); 

        $stop_bot->start(); 
        $dell_proc->start();

    	return redirect()->route('admin')->with('success', 'Бот выключен!');
    }
    
    /* Проверка баланса FKW */
    public function getBalans_frw() {
		$data = array(
			'wallet_id' => $this->settings->fk_wallet,
			'sign' => md5($this->settings->fk_wallet.$this->settings->fk_api),
			'action' => 'get_balance',
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://wallet.free-kassa.ru/api_v1.php');
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		$result = curl_exec($ch);
		curl_close($ch);

		$json = json_decode($result, true);

		if(!$json['status']) return;

		
    } 
    /*Проверка баланса FKW*/
    
	public function index()
    {
        $pay_today = SuccessPay::where('created_at', '>=', Carbon::today())->where('status', 1)->sum('price')/10;
		$pay_week = SuccessPay::where('created_at', '>=', Carbon::now()->subDays(7))->where('status', 1)->sum('price')/10;
		$pay_month = SuccessPay::where('created_at', '>=', Carbon::now()->subDays(30))->where('status', 1)->sum('price')/10;
		$pay_all = SuccessPay::where('status', 1)->sum('price')/10;
		$profit = Profit::where('day', '>=', Carbon::today())->sum('profit');
        
        $with_req = Withdraw::where('status', 0)->orderBy('id', 'desc')->sum('value');
        $fk_bal = $this->getBalans_frw();
        
        $payments = SuccessPay::where('status', 1)->orderBy('id', 'desc')->limit(10)->get();
        $users = User::orderBy('id', 'desc')->limit(10)->get();
        
        
		return view('admin.index', compact('with_req', 'fk_bal', 'pay_today', 'pay_week', 'pay_month', 'pay_all', 'with_req', 'fk_bal', 'last_dep', 'users', 'profit'));
    }
    
    public function users()
    {
        $users = User::where('fake', 0)->get();
		return view('admin.users', compact('users')); 
    }
    
    public function user($id)
    {
        $user = User::where('id', $id)->first();
        $promo = SuccessPay::where('user', $user->user_id)->where('status', 3)->sum('price')/10;
        $bon = Bonus::where('user_id', $user->id)->sum('bonus')/10;
        $dep = SuccessPay::where('user', $user->user_id)->where('status', 1)->sum('price')/10;
        $with = Withdraw::where('user_id', $user->id)->where('status', 1)->sum('value');
        $ref = $user->ref_money_history/10;
        
		$bonus = $promo + $bon;
		
		return view('admin.user', compact('user', 'dep', 'with', 'bonus', 'ref')); 
    }
    
    public function userSave(Request $r)
    {
        $admin = 0;
        $moder = 0;
        $youtuber = 0;
        if($r->get('id') == null) return redirect()->route('adminUsers')->with('error', 'Не удалось найти пользователя с таким ID!');
        if($r->get('balance') == null) return redirect()->route('adminUsers')->with('error', 'Поле "Баланс" не может быть пустым!');
        if($r->get('priv') == 'admin') {
            $admin = 1;
        }
        if($r->get('priv') == 'moder') {
            $moder = 1;
        }
        if($r->get('priv') == 'youtuber') {
            $youtuber = 1;
        }
        
        User::where('id', $r->get('id'))->update([
            'balance' => $r->get('balance'),
            'is_admin' => $admin,
            'is_moder' => $moder,
            'is_youtuber' => $youtuber,
            'ban' => $r->get('ban')
        ]);
		
        return redirect()->route('adminUsers')->with('success', 'Пользователь сохранен!');
    }
    
    public function settings()
    {
		return view('admin.settings'); 
    }
    
    public function settingsSave(Request $r)
    {
		Settings::where('id', 1)->update([
            'domain' => $r->get('domain'),
            'sitename' => $r->get('sitename'),
            'desc' => $r->get('desc'),
            'keys' => $r->get('keys'),
            'title' => $r->get('title'),
            'vk_url' => $r->get('vk_url'),
            'vk_key' => $r->get('vk_key'),
            'vk_secret' => $r->get('vk_secret'),
            'mrh_ID' => $r->get('mrh_ID'),
            'fk_wallet' => $r->get('fk_wallet'),
            'mrh_secret1' => $r->get('mrh_secret1'),
            'mrh_secret2' => $r->get('mrh_secret2'),
            'fk_api' => $r->get('fk_api'),
            'crash_timer' => $r->get('crash_timer'),
            'crash_min_bet' => $r->get('crash_min_bet'),
            'crash_max_bet' => $r->get('crash_max_bet'),
            'crash_profit' => $r->get('crash_profit'),
            'crash_pgames' => $r->get('crash_pgames'),
            'jackpot_maxbet' => $r->get('jackpot_maxbet'),
            'jackpot_timer_room1' => $r->get('jackpot_timer_room1'),
            'jackpot_min_bet_room_1' => $r->get('jackpot_min_bet_room_1'),
            'jackpot_max_bet_room_1' => $r->get('jackpot_max_bet_room_1'),
            'jackpot_timer_room2' => $r->get('jackpot_timer_room2'),
            'jackpot_min_bet_room_2' => $r->get('jackpot_min_bet_room_2'),
            'jackpot_max_bet_room_2' => $r->get('jackpot_max_bet_room_2'),
            'jackpot_timer_room3' => $r->get('jackpot_timer_room3'),
            'jackpot_min_bet_room_3' => $r->get('jackpot_min_bet_room_3'),
            'jackpot_max_bet_room_3' => $r->get('jackpot_max_bet_room_3'),
            'double_timer' => $r->get('double_timer'),
            'double_min_bet' => $r->get('double_min_bet'),
            'double_max_bet' => $r->get('double_max_bet')
        ]);
		
        return redirect()->route('adminSettings')->with('success', 'Настройки сохранен!');
    }
    
    public function promo() {
        $codes = Promocode::get();

        return view('admin.promo', compact('codes'));
    }
    
    public function promoNew(Request $r) {
        $code = $r->get('code');
        $limit = $r->get('limit');
        $amount = $r->get('amount');
        $count_use = $r->get('count_use');
        $have = Promocode::where('code', $code)->first();
        if(!$code) return redirect()->route('adminPromo')->with('error', 'Вы заполнили не все поля!');
        if(!$amount) return redirect()->route('adminPromo')->with('error', 'Вы заполнили не все поля!');
        if(!$count_use) return redirect()->route('adminPromo')->with('error', 'Вы заполнили не все поля!');
        if($have) return redirect()->route('adminPromo')->with('error', 'Такой код уже существует');
        
        Promocode::create([
            'code' => $code,
            'limit' => $limit,
            'amount' => $amount,
            'count_use' => $count_use
        ]);

        return redirect()->route('adminPromo')->with('success', 'Промокод создан!');
    }
    
    public function promoSave(Request $r) {
        $id = $r->get('id');
        $code = $r->get('code');
        $limit = $r->get('limit');
        $amount = $r->get('amount');
        $count_use = $r->get('count_use');
        $have = Promocode::where('code', $code)->where('id', '!=', $id)->first();
        if(!$id) return redirect()->route('adminPromo')->with('error', 'Не удалось найти данный ID!');
        if(!$code) return redirect()->route('adminPromo')->with('error', 'Вы заполнили не все поля!');
        if(!$amount) return redirect()->route('adminPromo')->with('error', 'Вы заполнили не все поля!');
        if(!$count_use) $count_use = 0;
        if($have) return redirect()->route('adminPromo')->with('error', 'Такой код уже существует');
        
        Promocode::where('id', $id)->update([
            'code' => $code,
            'limit' => $limit,
            'amount' => $amount,
            'count_use' => $count_use
        ]);

        return redirect()->route('adminPromo')->with('success', 'Промокод обновлен!');
    }
    
    public function promoDelete($id) {
        if(!$id) return redirect()->route('adminPromo')->with('error', 'Нет такого промокода!');
        Promocode::where('id', $id)->delete();
        
        return redirect()->route('adminPromo')->with('success', 'Промокод удален!');
    }
    
    public function withdraw() {
        $list = Withdraw::where('status', 0)->get();
        $withdraws = [];
        foreach($list as $itm) {
            $user = User::where('id', $itm->user_id)->first();
            $withdraws[] = [
                'id' => $itm->id,
                'user_id' => $user->id,
                'username' => $user->username,
                'avatar' => $user->avatar,
                'system' => $itm->system,
                'wallet' => $itm->wallet,
                'value' => $itm->value,
                'status' => $itm->status
            ];
        }
        
        $list2 = Withdraw::where('status', 1)->get();
        $finished = [];
        foreach($list2 as $itm) {
            $user = User::where('id', $itm->user_id)->first();
            $finished[] = [
                'id' => $itm->id,
                'user_id' => $user->id,
                'username' => $user->username,
                'avatar' => $user->avatar,
                'system' => $itm->system,
                'wallet' => $itm->wallet,
                'value' => $itm->value,
                'status' => $itm->status
            ];
        }
        
        return view('admin.withdraw', compact('withdraws', 'finished'));
    }
    
    public function withdrawSend($id) {
        $withdraw = Withdraw::where('id', $id)->first();
		$balance_fk = $this->getBalans_frw();
        
		if($withdraw->system == 'qiwi') {
            $system = 63;
            $com = 1;
            $perc = 4;
        }
		if($withdraw->system == 'webmoney') {
            $system = 1;
            $com = 0;
            $perc = 6;
        }
		if($withdraw->system == 'yandex') {
            $system = 45;
            $com = 0;
            $perc = 0;
        }
		if($withdraw->system == 'fk') {
            $system = 133;
            $com = 0;
            $perc = 0;
        }
		if($withdraw->system == 'visa') {
            $system = 94;
            $com = 50;
            $perc = 4;
        }
        
        if($balance_fk < $withdraw->value) return redirect()->route('adminWithdraw')->with('error', 'На вашем кошельке недостаточно средств! Доступно: '.$balance_fk.'р.');
		
		$data = array(
			'wallet_id' => $this->settings->fk_wallet,
			'purse' => $withdraw->wallet,
			'amount' => $withdraw->value,
			'desc' => 'Withdraw for user #'.$withdraw->user_id,
			'currency' => $system,
			'sign' => md5($this->settings->fk_wallet.$system.$withdraw->value.$withdraw->wallet.$this->settings->fk_api),
			'action' => 'cashout',
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://www.fkwallet.ru/api_v1.php');
		curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = trim(curl_exec($ch));
        $c_errors = curl_error($ch);
        curl_close($ch);
		
		$json = json_decode($result, true);
		if($json['status'] == 'error') {
			if($json['desc'] == 'Balance too low') {
				$desc = 'Попробуйте пожалуйста позже.';
                return redirect()->route('adminWithdraw')->with('error', $desc);
			} elseif($json['desc'] == 'Cant make payment to anonymous wallets') {
				$desc = 'Данный пользователь использует анонимный кошелек!.';
                return redirect()->route('adminWithdraw')->with('error', $desc);
			} elseif($json['desc'] == 'РЎР»РёС€РєРѕРј С‡Р°СЃС‚С‹Рµ Р·Р°РїСЂРѕСЃС‹ Рє API') {
				$desc = 'Неизвестная ошибка!.';
                return redirect()->route('adminWithdraw')->with('error', $desc);
			} else {
                return redirect()->route('adminWithdraw')->with('error', $json['desc']);
            }
            
		}
		
		if($json['status'] == 'info') {
            $withdraw->status = 1;
            $withdraw->save();
            return redirect()->route('adminWithdraw')->with('success', 'Ваша заявка поставлена в очередь. Вывод происходит в течении 24 часов.');
		}
    }
    
    public function withdrawReturn($id) {
        $withdraw = Withdraw::where('id', $id)->first();
        $user = User::where('id', $withdraw->user_id)->first();
        
        if($withdraw->system == 'qiwi') {
            $perc = 4;
            $com = 1;
            $min = 100;
        } elseif($withdraw->system == 'webmoney') {
            $perc = 6;
            $com = 0;
            $min = 10;
        } elseif($withdraw->system == 'yandex') {
            $perc = 0;
            $com = 0;
            $min = 10;
        } elseif($withdraw->system == 'visa') {
            $perc = 4;
            $com = 50;
            $min = 1000;
        }
        
        $valwithcom = ($withdraw->value+($min/100*$perc)+$com)*10;
        if($user->is_youtuber) $valwithcom = $val/10;
        
        $user->balance += $valwithcom;
        
        $withdraw->status = 2;
        $withdraw->save();
        
        return redirect()->route('adminWithdraw')->with('success', 'Вы выплатили '.$withdraw->value.'р. игроку '.$user->username);
    }
    
    
        
      }