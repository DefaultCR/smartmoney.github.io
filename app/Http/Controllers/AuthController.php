<?php namespace App\Http\Controllers;

use Socialite;
use App\User;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{

    public function login($provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    public function callback($provider)
    {
        $user = json_decode(json_encode(Socialite::driver($provider)->user()));
        if(isset($user->returnUrl)) return redirect('/');
        $user = $user->user;
        $user = $this->createOrGetUser($user, $provider);
        Auth::login($user, true);
        return redirect()->intended('/');
    }

    public function createOrGetUser($user, $provider)
    {
        if ($provider == 'vkontakte') {
            $u = User::where('user_id', $user->id)->first();
            if ($u) {
                $username = $user->first_name.' '.$user->last_name;
                User::where('user_id', $user->id)->update([
                    'username' => $username,
                    'avatar' => $user->photo_200,
                    'ip' => request()->ip()
                ]);
                $user = $u;
            } else {
                $username = $user->first_name.' '.$user->last_name;
                $user = User::create([
                    'unique_id' => str_random(4),
                    'user_id' => $user->id,
                    'username' => $username,
                    'avatar' => $user->photo_200,
                    'affiliate_id' => str_random(10),
                    'ip' => request()->ip()
                ]);
            }
        }
        return $user;
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->intended('/');
    }




private function getUniqueID()
    {
        $uid = '';
        for($i = 0; $i < mt_rand(4,6); $i++) $uid .= ($i > 0) ? mt_rand(1,9) : mt_rand(0,9);
        
        // check
        $user = User::where('id', $uid)->first();
        if(is_null($user)) return $uid;
        return $this->getUniqueID();
    }
}
    