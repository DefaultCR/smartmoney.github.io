<?php namespace App\Http\Controllers;

use Auth;
use App\User;
use App\Bonus;
use App\Settings;
use App\Promocode;
use App\Payments;
use App\Withdraw;
use App\SuccessPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Carbon\Carbon;

class PageController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }
	
	public function referral() {
        $ref = User::where('referred_by', $this->user->affiliate_id)->count();
		return view('pages.referral', compact('ref'));
	}
    
    public function refActivate(Request $r) {
        $code = $r->get('code');
        
        if(!$code) return [
            'success' => false,
            'msg' => 'Вы не ввели код!',
            'type' => 'error'
        ];
        
        $refcode = User::where('affiliate_id', $code)->first();
        $promocode = Promocode::where('code', $code)->first();
        
        if(!$refcode && !$promocode) return [
            'success' => false,
            'msg' => 'Такого кода не существует!',
            'type' => 'error'
        ];
        
        if($refcode) {
            $money = 100;
            if($code == $this->user->affiliate_id) return [
                'success' => false,
                'msg' => 'Вы не можете активировать свой код!',
                'type' => 'error'
            ];

            if($this->user->referred_by) return [
                'success' => false,
                'msg' => 'Вы уже активировали код!',
                'type' => 'error'
            ];

            $this->user->balance += $money;
            $this->user->referred_by = $code;
            $this->user->save();
            
            SuccessPay::insert([
                'user' => $this->user->user_id,
                'price' => $money,
                'code' => $code,
                'status' => 2,
            ]);
        }
        if($promocode) {
            $money = $promocode->amount;
            $check = SuccessPay::where('user', $this->user->user_id)->where('code', $code)->first();
            
            if($check) return [
                'success' => false,
                'msg' => 'Вы уже активировали код!',
                'type' => 'error'
            ];
            
            if($promocode->limit == 1 && $promocode->count_use <= 0) return [
                'success' => false,
                'msg' => 'Код больше не действителен!',
                'type' => 'error'
            ];

            $this->user->balance += $money;
            $this->user->save();
            
            if($promocode->limit == 1 && $promocode->count_use > 0){
                $promocode->count_use -= 1;
                $promocode->save();
            }
            
            SuccessPay::insert([
                'user' => $this->user->user_id,
                'price' => $money,
                'code' => $code,
                'status' => 3,
            ]);
        }
        
        $this->redis->publish('updateBalance', json_encode([
            'id'    => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        return [
            'success' => true,
            'msg' => 'Код активирован!',
            'type' => 'success'
        ];
    }
    
    public function getMoney() {
        $ref_money = floor($this->user->ref_money);
        if($ref_money < 0.99) return [
            'success' => false,
            'msg' => 'Вам нечего забирать!',
            'type' => 'error'
        ];
        $this->user->balance += $ref_money;
        $this->user->ref_money -= $ref_money;
        $this->user->save();
        
        $this->redis->publish('updateBalance', json_encode([
            'id'    => $this->user->id,
            'balance' => $this->user->balance
        ]));
        
        return [
            'success' => true,
            'msg' => 'Вы забрали монеты!',
            'type' => 'success'
        ];
    }
    
    public function bonus() {
        return view('pages.bonus');
    }
    
    public function getBonus(Request $r) {
		$validator = \Validator::make($r->all(), [
            'recapcha' => 'required|captcha',
        ]);
		
		if($validator->fails()) {
            return [
				'success' => false,
				'msg' => 'Вы не прошли проверку на я не робот!',
				'type' => 'error'
			];
        }
		
        $req = $r->get('bonus');
        if(is_null($req)) return [
            'success' => false,
            'msg' => 'Вы что-то делаете не так!',
            'type' => 'error'
        ];
        if($req == 0) $give = 2;
        if($req == 1) $give = 30;
        
        if($req == 0) {
            $nowtime = date("H", time());
            if($nowtime < 0) return [
                'success' => false,
                'msg' => 'Бонус можно получить 24/7',
                'type' => 'error'
            ];
        }
        
        $bonus = Bonus::where('user_id', $this->user->id)->where('type', $req)->orderBy('id', 'desc')->first();
        $vk_ckeck = $this->groupIsMember($this->user->user_id);
        
        if($vk_ckeck == 0) {
            return [
                'success' => false,
                'msg' => 'Вы не состоите в нашей группе!',
                'type' => 'error'
            ];
        }
        
        if($vk_ckeck == NULL) {
            return [
                'success' => false,
                'msg' => 'Выдача бонусов временно не работает!',
                'type' => 'error'
            ];
        }
        if($bonus) {
            if($bonus->remaining) {
                $nowtime = time();
                $time = $bonus->remaining;
                $lasttime = $nowtime - $time;
                if($time >= $nowtime) {
                    return [
                        'success' => false,
                        'msg' => 'Следующий бонус сможете получить: '.date("d.m.Y H:i:s", $time),
                        'type' => 'error'
                    ];
                }
            }
            $bonus->status = 2;
            $bonus->save();
        }

        if($req == 0) $remaining = Carbon::now()->addMinutes(5)->getTimestamp();
        if($req == 1) $remaining = Carbon::now()->addDay(1)->getTimestamp();
        
        Bonus::create([
            'user_id' => $this->user->id,
            'bonus' => $give,
            'remaining' => $remaining,
            'type' => $req,
            'status' => 1
        ]);

        $this->user->balance += $give;
        $this->user->save();

        return [
            'success' => true,
            'msg' => 'Вы получили бонус в размере '.$give.' монет(ы)!',
            'type' => 'success'
        ];
    }
    
    public function takeBonusVK(Request $r) {
        $user = User::where('user_id', $r->get('user_id'))->first();
        
        if($user->vkBonus) return [
            'success' => false,
            'msg' => 'Вы уже получили бонус за рассылку уведомлений!',
            'type' => 'error'
        ];
        
        $user->balance += 30;
        $user->vkBonus = 1;
        $user->save();
        
        $this->redis->publish('updateBalance', json_encode([
            'id'    => $user->id,
            'balance' => $user->balance
        ]));
        
        return [
            'success' => true,
            'msg' => 'Вы получили бонус в размере 30 монет!',
            'type' => 'success'
        ];
    }
    
    private function groupIsMember($id) {
        $user_id = $id;
        $vk_url = $this->settings->vk_url;
        if(!$vk_url) $group = NULL;
        $old_url = ($vk_url);
        $url = explode('/', trim($old_url,'/'));
        $url_parse = array_pop($url);
        $url_last = preg_replace('/&?club+/i', '', $url_parse);
        $runfile = 'https://api.vk.com/method/groups.isMember?v=5.3&group_id='.$url_last.'&user_id='.$user_id.'&access_token='.$this->settings->vk_secret;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $runfile);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $group = curl_exec($ch);
        curl_close($ch);
        $group = json_decode($group, true);
        
        if(isset($group['error'])) {
            $group = NULL;
        } else {
            $group = $group['response']; // Получаем массив комментариев
        }
        return $group;
    }
    
    public function youtube() {
        return view('pages.youtube');
    }
    
    public function faq() {
        return view('pages.faq');
    }

   public function about() {
        return view('pages.about');
    }
    
    public function terms() {
        return view('pages.terms');
    }
    
    public function test() {
        $users = User::get();
        foreach($users as $user) {
            $rand = rand(1, 25000);
            if($user->unique_id === $rand) $rand = rand(1, 25000);
            $user->unique_id = $rand;
            $user->save();
        }
        return 'ok';
    }
}