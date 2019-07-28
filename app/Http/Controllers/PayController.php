<?php namespace App\Http\Controllers;

use Auth;
use App\User;
use App\Settings;
use App\Payments;
use App\Withdraw;
use App\SuccessPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Carbon\Carbon;

class PayController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }
	
	public function payin() {
		return view('pages.payin');
	}
    
	public function payout() {
		return view('pages.payout');
	}
    
	public function paysend() {
		return view('pages.paysend');
	}
    
	public function payhistory() {
        $pays = SuccessPay::where('user', $this->user->user_id)->where('status', '>', 1)->get();
        $withdraws = Withdraw::where('user_id', $this->user->id)->get();
		return view('pages.payhistory', compact('pays', 'withdraws'));
	}
    
    public function pay(Request $r)
	{
        $sum = $r->get('num');
		
		if(!$sum) return \Redirect::back();
        /*CREATE PAY*/
       
 $pay = [
            'secret' => md5($this->settings->mrh_ID . ":" . $sum . ":" . $this->settings->mrh_secret1 . ":" . $this->settings->order_id),
            'merchant_id' => $this->settings->mrh_ID,
            'order_id' => $this->settings->order_id,
            'sum' => $r->get('num'),
            'user_id' => $this->user->user_id
        ];
        Payments::insert($pay);
        /*REDIRECT*/
        
        Settings::where('id', 1)->update([
            'order_id' => $this->settings->order_id+1 
        ]);
        
        return Redirect('https://www.free-kassa.ru/merchant/cash.php?m='.$this->settings->mrh_ID.'&oa='.$r->get('num').'&o='.$pay['order_id'].'&s='.md5($this->settings->mrh_ID.':'.$sum.':'.$this->settings->mrh_secret1.':'.$pay['order_id']));
        
	}
	
	public function result(Request $r)
	{
        $ip = true;
        if(isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $this->getIp($_SERVER['HTTP_X_REAL_IP']);
        } else {
            $ip = $this->getIp($_SERVER['REMOTE_ADDR']);
        }
        if(!$ip) return redirect()->route('index')->with('error', 'Ошибка при проверке IP free-kassa!');
        /* SEARCH MERCH */
        $merch = Payments::where('order_id', $r->get('MERCHANT_ORDER_ID'))->first();
		$merch_order_id = $r->get('MERCHANT_ORDER_ID');
        if(!$merch) return redirect()->route('index')->with('error', 'Не удалось найти заказ #'.$r->get('MERCHANT_ORDER_ID'));
        if($merch->status == 1) return redirect()->route('index')->with('error', 'Заказ уже оплачен!');
        /* ADD BALANCE TO USER */
        #check amount
        if($r->get('AMOUNT') != $merch->sum) return 'Вы оплатили не тот заказ!';
        
        $user = User::where('user_id', $merch->user_id)->first();
        if(!$user) return redirect()->route('index')->with('error', 'Не удалось найти пользователя!');
        
		/* ADD Balance from user and partner */
        $sum = floor($merch->sum*10);
        User::where('user_id', $user->user_id)->update([
            'balance' => $user->balance+$sum 
        ]);
		if(!is_null($user->referred_by)) {
			$partner = User::where('affiliate_id', $user->referred_by)->first();
			$partner->balance += $sum / 100 * 10;
			$partner->save();
		}
        
        /*UPDATE MERCH STATUS*/
        Payments::where('order_id', $merch_order_id)->update([
            'status' => 1 
        ]);
		
		SuccessPay::insert([
        	'user' => $user->user_id,
            'price' => $sum,
            'status' => 1,
       	]);
        
        $this->sendToWallet($sum);
        
        /* SUCCESS REDIRECT */
        return redirect()->route('index');
	}
    
    /* CHECK FREE KASSA IP */
    
function getIp($ip) {
        $list = ['136.243.38.108', '136.243.38.147', '136.243.38.149', '136.243.38.150', '136.243.38.151', '136.243.38.189', '88.198.88.98', '37.1.14.226'];
        for($i = 0; $i < count($list); $i++) {
            if($list[$i] == $ip) return true;
        }
        return false;
    }
    
    public function getMerchBalance() {
        $sign = md5($this->settings->mrh_ID.$this->settings->mrh_secret2);
        $xml_string = file_get_contents('http://www.free-kassa.ru/api.php?merchant_id='.$this->settings->mrh_ID.'&s='.$sign.'&action=get_balance');
        
        $xml = simplexml_load_string($xml_string);
        $json = json_encode($xml);
        $balance = json_decode($json, true);
        
        if($balance['answer'] == 'info') {
            $sum = $balance['balance'];
            if($sum >= 50) {
                sleep(11);
                return $this->sendToWallet($sum);
            } else {
                return [
                    'msg' => 'Not enough money on the balance of the merchant!',
                    'type' => $balance['answer']
                ];
            }
        } else {
            return [
                'msg' => $balance['desc'],
                'type' => $balance['answer']
            ];
        }
    }
	
	public function sendToWallet($sum) {
        $sign = md5($this->settings->mrh_ID.$this->settings->mrh_secret2);
        $xml_string = file_get_contents('http://www.free-kassa.ru/api.php?currency=fkw&merchant_id='.$this->settings->mrh_ID.'&s='.$sign.'&action=payment&amount='.$sum);
        
        $xml = simplexml_load_string($xml_string);
        $json = json_encode($xml);
        $res = json_decode($json, true);
        
        if($res['answer'] == 'info') {
            return [
                'msg' => $res['desc'].', PaymentId - '.$res['PaymentId'],
                'type' => $res['answer']
            ];
        } else {
            return [
                'msg' => $res['desc'],
                'type' => $res['answer']
            ];
        }
        return $res;
    }
	
	public function success() {
		return redirect()->route('index')->with('success', 'Ваш баланс успешно пополнен!');
	}
	
	public function fail() {
		return redirect()->route('index')->with('error', 'Ошибка при пополнении баланса!');
	}
    
    public function withdraw(Request $r) {
        $system = $r->get('system');
        $value = $r->get('value');
        $wallet = str_replace([' ', '+', '(', ')', '-'], '', $r->get('wallet'));
        $val = floor($value);
        
        $dep = SuccessPay::where('user', $this->user->user_id)->where('status', 1)->sum('price')/10;
        
        if($dep < 70) return [
            'success' => false,
            'msg' => 'Вам необходимо пополнить счет на 70 рублей для вывода средств!',
            'type' => 'error'
        ];
        
           if($system == 'qiwi') {
            $perc = 4;
            $com = 1;
            $min = 1;
            $min = 300;
        
        } elseif($system == 'yandex') {
            $perc = 0;
            $com = 1;
            $min = 1;
            $min = 300;
        }
   

        
        $valwithcom = ($val-($min/100*$perc)-$com*10)/10;
        if($this->user->is_youtuber) $valwithcom = $val/10;
        
        if($system == 'qiwi' && $valwithcom < 30) {
            return [
                'success' => false,
                'msg' => 'Минимальная сумма для вывода 30 рублей с учетом комиссии!',
                'type' => 'error'
            ];
        } elseif($system == 'webmoney' && $valwithcom < 10) {
            return [
                'success' => false,
                'msg' => 'Минимальная сумма для вывода 10 рублей с учетом комиссии!',
                'type' => 'error'
            ];
        } elseif($system == 'yandex' && $valwithcom < 30) {
            return [
                'success' => false,
                'msg' => 'Минимальная сумма для вывода 30 рублей с учетом комиссии!',
                'type' => 'error'
            ];
        } elseif($system == 'visa' && $valwithcom < 1000) {
            return [
                'success' => false,
                'msg' => 'Минимальная сумма для вывода 1000 рублей с учетом комиссии!',
                'type' => 'error'
            ];
        }
        
        if($valwithcom > 2000) {
            return [
                'success' => false,
                'msg' => 'Максимальная сумма для вывода 2,000 рублей',
                'type' => 'error'
            ];
        }
        
        if($valwithcom == 0) return [
            'success' => false,
            'msg' => 'Не правильно введена сумма!',
            'type' => 'error'
        ];
        if(is_null($system) || is_null($val) || is_null($wallet)) return [
            'success' => false,
            'msg' => 'Вы не заполнили один из пунктов!',
            'type' => 'error'
        ];
        if($val > $this->user->balance) return [
            'success' => false,
            'msg' => 'Вы не можете вывести сумму больше чем Ваш баланс!',
            'type' => 'error'
        ];
        
        Withdraw::insert([
            'user_id' => $this->user->id,
            'value' => $valwithcom,
            'system' => $system,
            'wallet' => $wallet
        ]);
        
        $this->user->balance -= $val;
        $this->user->save();
        
        $this->redis->publish('updateBalance', json_encode([
            'user' => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        return [
            'success' => true,
            'msg' => 'Вы оставили заявку на вывод!',
            'type' => 'success'
        ];
    }
    
    public function sendCreate(Request $r) {
        $target = $r->get('target');
        $sum = $r->get('sum');
        $value = floor($sum*1.05);
        
        $with = Withdraw::where('user_id', $this->user->id)->where('status', 1)->sum('value');
        $user = User::where('unique_id', $target)->first();
        
        if($with < 0) return [
            'success' => false,
            'msg' => 'Вы не сделали вывод в размере 250 рублей!',
            'type' => 'error'
        ];
        
        if(!$user) return [
            'success' => false,
            'msg' => 'Пользователя с таким ID нет!',
            'type' => 'error'
        ];
        
        if($target == $this->user->unique_id) return [
            'success' => false,
            'msg' => 'Вы не можете отправлять монеты себе!',
            'type' => 'error'
        ];
        
        if($value > $this->user->balance) return [
            'success' => false,
            'msg' => 'Вы не можете отправить сумму больше чем Ваш баланс!',
            'type' => 'error'
        ];
        
        if($value < 20) return [
            'success' => false,
            'msg' => 'Минимальная сумма перевода 20 монет!',
            'type' => 'error'
        ];
        
        if(!$value || !$target) return [
            'success' => false,
            'msg' => 'Вы не вели одно из значений!',
            'type' => 'error'
        ];
        
        $this->user->balance -= $value;
        $this->user->save();
        
        $user->balance += $sum;
        $user->save();
        
        $this->redis->publish('updateBalance', json_encode([
            'id'      => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        $this->redis->publish('updateBalance', json_encode([
            'id'      => $user->id,
            'balance' => $user->balance
        ]));
        
        return [
            'success' => true,
            'msg' => 'Вы перевели '.$sum.' монет пользователю '.$user->username.'!',
            'type' => 'success'
        ];        
    }
}