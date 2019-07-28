<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class CoinFlip extends Model{

    protected $table = 'flip_rooms';

    protected $fillable = ['winnerid', 'user1', 'user2', 'coins_user1', 'coins_user2', 'price', 'hash', 'rand_number'];

    public $timestamps = true;
	
	public static function allmoney()
    {
        $maxmoney = \DB::table('rooms')->sum('price');
    }

}