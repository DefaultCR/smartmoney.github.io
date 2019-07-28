<?php

namespace App\Http\Controllers;

use App\User;
use App\Settings;
use App\Room1;
use App\Room2;
use App\Room3;
use App\Withdraw;
use App\Http\Controllers\ChatController;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Redis;
use Auth;
use DB;
use Carbon\Carbon;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $user;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->user = Auth::user();
            view()->share('u', $this->user);
            return $next($request);
        });
        Carbon::setLocale('ru');
        $this->settings = Settings::first();
        $this->redis = Redis::connection();
        view()->share('messages', $this->chatMessage());
        view()->share('settings', $this->settings);
        view()->share('maxPriceToday', $this->maxPriceToday());
        view()->share('maxPrice', $this->maxPrice());
        view()->share('gamesToday', $this->gamesToday());
        view()->share('groupID', $this->groupID());
        view()->share('withdrawal', $this->withdrawal());
        view()->share('withdrawSum', $this->withdrawSum());
    }
    
    public function groupID() {
        $vk_url = $this->settings->vk_url;
        if(!$vk_url) $group = 9798985;
        $old_url = ($vk_url);
        $url = explode('/', trim($old_url,'/'));
        $url_parse = array_pop($url);
        $url_last = preg_replace('/&?club+/i', '', $url_parse);
        $runfile = 'https://api.vk.com/method/groups.getById?v=5.3&group_id='.$url_last.'&access_token=86a20ea386a20ea386a20ea35686c0f10c886a286a20ea3dda76cc9f02a52c0c5094aa9';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $runfile);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $group = curl_exec($ch);
        curl_close($ch);
        $group = json_decode($group, true);
        
        if(isset($group['error'])) {
            $group = 9798985;
        } else {
            $group = '-'.$group['response'][0]['id']; // Получаем массив комментариев
        }
        return $group;
    }
    
    public function chatMessage() {
        $messages = ChatController::chat();
        return $messages;
    }
    
	public static function maxPriceToday() {
		$room1 = ($price = Room1::where('updated_at', '>=', Carbon::today())->max('price')) ? $price : 0;
		$room2 = ($price = Room2::where('updated_at', '>=', Carbon::today())->max('price')) ? $price : 0;
		$room3 = ($price = Room3::where('updated_at', '>=', Carbon::today())->max('price')) ? $price : 0;
		
		$price = max($room1, $room2, $room3);
        return $price;
    }
    
    public static function maxPrice() {
        $room1 = Room1::where('status', 3)->max('price');
        $room2 = Room2::where('status', 3)->max('price');
        $room3 = Room3::where('status', 3)->max('price');
		
		$games = max($room1, $room2, $room3);
		return $games;
    }
	
    public static function gamesToday() {
        $room1 = Room1::where('status', 3)->where('updated_at', '>=', Carbon::today())->count();
        $room2 = Room2::where('status', 3)->where('updated_at', '>=', Carbon::today())->count();
        $room3 = Room3::where('status', 3)->where('updated_at', '>=', Carbon::today())->count();
		
		$games = $room1+$room2+$room3;
		return $games;
    }
	
    public static function withdrawal() {
        $withdraw = Withdraw::where('status', 1)->orderBy('id', 'desc')->limit(5)->get();
        $withdraw_sum = Withdraw::where('status', 1)->sum('value');
		
		$withdrawal = [];
		foreach($withdraw as $w) {
			$user = User::where('id', $w->user_id)->first();
			$withdrawal[] = [
				'id' =>	$w->id,
				'user_vk' => $user->user_id,
				'avatar' => $user->avatar,
				'name' => $user->username,
				'sum' => floor($w->value)
			];
		}
		
		return $withdrawal;
    }
	
    public static function withdrawSum() {
        $withdraw_sum = Withdraw::where('status', 1)->sum('value');
		
		return floor($withdraw_sum);
    }

    public function tf($n1)
    {
        $list = [];
        for($i = 0; $i < $n1; $i++) $list[] = true;
        for($i = 0; $i < (100-$n1); $i++) $list[] = false;
        shuffle($list);
        return $list[mt_rand(0, count($list)-1)];
    }

    public function makeProfit()
    {
        // check profit date
        if($this->settings->sys_profit_timestamp < Carbon::now('MSK')->today()) 
        {
            DB::table('profit')->insert([
                'profit' => $this->settings->sys_profit,
                'day' => $this->settings->sys_profit_timestamp
            ]);
            $this->settings->sys_profit_timestamp = Carbon::now('MSK')->today();
            $this->settings->sys_profit = 0;
            $this->settings->save();
        }

        if($this->settings->sys_profit < 1000) return true;
        if($this->tf(80)) return false; else return true;
        return false;
    }
}