<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests;
use App\User;
use App\SuccessPay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Redis;

class ChatController extends Controller
{
    const CHAT_CHANNEL = 'chat.message';
    const NEW_MSG_CHANNEL = 'new.msg';
    const CLEAR = 'chat.clear';
    const DELETE_MSG_CHANNEL = 'del.msg';

    public function __construct()
    {
        parent::__construct();
        $this->redis = Redis::connection();
    }
	
    public static function chat()
    {
        $redis = Redis::connection();

        $value = $redis->lrange(self::CHAT_CHANNEL, 0, -1);
        $i = 0;
        $returnValue = NULL;
        $value = array_reverse($value);

        foreach ($value as $key => $newchat[$i]) {
            if ($i > 20) {
                break;
            }
            $value2[$i] = json_decode($newchat[$i], true);

            $value2[$i]['username'] = htmlspecialchars($value2[$i]['username']);

            $returnValue[$i] = [

				'user_id' => $value2[$i]['user_id'],
                'avatar' => $value2[$i]['avatar'],
                'time' => $value2[$i]['time'],
                'time2' => $value2[$i]['time2'],
                'ban' => $value2[$i]['ban'],
                'messages' => $value2[$i]['messages'],
                'username' => $value2[$i]['username'],
                'youtuber' => $value2[$i]['youtuber'],
                'moder' => $value2[$i]['moder'],
                'admin' => $value2[$i]['admin']];

            $i++;

        }

       if(!is_null($returnValue)) return array_reverse($returnValue);
    }


    public function __destruct()
    {
        $this->redis->disconnect();
    }

    public function add_message(Request $request)
    {
        $val = \Validator::make($request->all(), [
            'messages' => 'required|string|max:255'
        ],[
            'required' => 'Сообщение не может быть пустым!',
            'string' => 'Сообщение должно быть строкой!',
            'max' => 'Максимальный размер сообщения 255 символов.',
        ]);
        $error = $val->errors();

        if($val->fails()){
            return response()->json(['message' => $error->first('messages'), 'status' => 'error']);
        }
        
        $messages = $request->get('messages');
        if(\Cache::has('addmsg.user.' . $this->user->id)) return response()->json(['message' => 'Вы слишком часто отправляете сообщения!', 'status' => 'error']);
        \Cache::put('addmsg.user.' . $this->user->id, '', 0.05);
        $nowtime = time();
        $banchat = $this->user->banchat;
        $lasttime = $nowtime - $banchat;
        $dep = SuccessPay::where('user', $this->user->user_id)->where('status', 1)->sum('price')/10;
        /*if(!$this->user->is_admin && !$this->user->is_moder && !$this->user->is_youtuber) {
            if($dep < 10) {
                return response()->json(['message' => 'Для того чтобы писать в чат, вам нужно пополнить счет на 10 рублей!', 'status' => 'error']);
            }
        }*/
        
        if($banchat >= $nowtime) {
            return response()->json(['message' => 'Вы заблокированы до: '.date("d.m.Y H:i:s", $banchat), 'status' => 'error']);
        } else {
            User::where('unique_id', $this->user->unique_id)->update(['banchat' => null]);
        }
		
        $time = date('H:i', time());
        $moder = $this->user->is_moder;
        $youtuber = $this->user->is_youtuber;
        $admin = 0;
        $ban = $this->user->banchat;
		$user_id = $this->user->unique_id;
        $username = htmlspecialchars($this->user->username);
        $avatar = $this->user->avatar;
        if($this->user->is_admin) {
            if(strpos($messages, '/admin') !== false) {
                $admin = 1;
                $messages = str_replace('/admin ', '', $messages);
            }
        }
        if ($admin) {
            $avatar = '/img/avatar.png';
            $user_id = 0;
        }

        function object_to_array($data) {
            if (is_array($data) || is_object($data)) {
                $result = array();
                foreach ($data as $key => $value) {
                    $result[$key] = object_to_array($value);
                }
                return $result;
            }
            return $data;
        }

        $words = file_get_contents(dirname(__FILE__) . '/words.json');
        $words = object_to_array(json_decode($words));

        foreach ($words as $key => $value) {
            $messages = str_ireplace($key, $value, $messages);
        }

        if($this->user->is_admin || $this->user->is_moder) {
            if (substr_count($messages, '/clear')) {
                $this->redis->del(self::CHAT_CHANNEL);
                $this->redis->publish(self::CLEAR, 1);
                return response()->json(['message' => 'Вы очистили чат!', 'status' => 'success']);
            } elseif(substr_count($messages, '/ban ')) {
                $admin = $this->user->is_admin;
                if ($admin) {
                    $avatar = '/img/avatar.png';
                    $user_id = 0;
                }
                $rep = str_replace("/ban ", "", $messages);
                $mes = explode(" ", $rep);
                $usr = User::where('unique_id', $mes[0])->first();
                if($usr->unique_id == $this->user->unique_id) return response()->json(['message' => 'Вы не можете заблокировать себя!', 'status' => 'error']);
                if (!empty($mes[1])) {
                    User::where('unique_id', $usr->unique_id)->update(['banchat' => Carbon::now()->addMinutes($mes[1])->getTimestamp()]);
                } else {
                    return response()->json(['message' => 'Вы не ввели ID игрока или время бана', 'status' => 'error']);
                }
                $returnValue = ['user_id' => $user_id, 'avatar' => $avatar, 'time2' => Carbon::now()->getTimestamp(), 'time' => $time, 'messages' => '<span style="color:red;">Пользователь</span> <span style="color: #E03A3A;">'.$usr->username.'</span> <span style="color:red;">заблокирован в чате на '.$mes[1].' мин.</span>', 'username' => $username, 'ban' => 0, 'admin' => $admin, 'moder' => $moder, 'youtuber' => $youtuber];
                $this->redis->rpush(self::CHAT_CHANNEL, json_encode($returnValue));
                $this->redis->publish(self::NEW_MSG_CHANNEL, json_encode($returnValue));
                return response()->json(['message' => 'Вы успешно забанили игрока', 'status' => 'success']);
            }
            if(substr_count($messages, '/unban')) {
                $admin = $this->user->is_admin;
                if ($admin) {
                    $avatar = '/img/avatar.png';
                    $user_id = 0;
                }
                $userid = str_replace("/unban ", "", $messages);
                $usr = User::where('unique_id', $userid)->first();
                if($usr->unique_id == $this->user->unique_id) return response()->json(['message' => 'Вы не можете разблокировать себя!', 'status' => 'error']);
                if (!empty($userid)) {
                    User::where('unique_id', $usr->unique_id)->update(['banchat' => null]);
                } else {
                    return response()->json(['message' => 'Вы не ввели ID игрока', 'status' => 'error']);
                }
                $returnValue = ['user_id' => $user_id, 'avatar' => $avatar, 'time2' => Carbon::now()->getTimestamp(), 'time' => $time, 'messages' => '<span style="color:red;">Пользователь "'.$usr->username.'" разблокирован в чате</span>', 'username' => $username, 'ban' => 0, 'admin' => $admin, 'moder' => $moder, 'youtuber' => $youtuber];
                $this->redis->rpush(self::CHAT_CHANNEL, json_encode($returnValue));
                $this->redis->publish(self::NEW_MSG_CHANNEL, json_encode($returnValue));
                return response()->json(['message' => 'Вы успешно разбанили игрока', 'status' => 'success']);
            }
        } else {
			if(preg_match("/href|url|http|https|www|.ru|.com|.net|.info|csgo|winner|ru|xyz|com|net|info|.org/i", $messages)) {
				return response()->json(['message' => 'Ссылки запрещены!', 'status' => 'error']);
            }
            if(substr_count(str_replace(' ', '', $messages), $this->user->affiliate_id)) {
				return response()->json(['message' => 'Отправка промокодов запрещена!', 'status' => 'error']);
            }

        }
        $returnValue = ['user_id' => $user_id, 'avatar' => $avatar, 'time2' => Carbon::now()->getTimestamp(), 'time' => $time, 'messages' => htmlspecialchars($messages), 'username' => $username, 'ban' => $ban, 'admin' => $admin, 'moder' => $this->user->is_moder, 'youtuber' => $this->user->is_youtuber];
        $this->redis->rpush(self::CHAT_CHANNEL, json_encode($returnValue));
        $this->redis->publish(self::NEW_MSG_CHANNEL, json_encode($returnValue));
		return response()->json(['message' => 'Ваше сообщение успешно отправлено!', 'status' => 'success']);
	}
}
