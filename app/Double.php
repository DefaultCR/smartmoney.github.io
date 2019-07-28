<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use DB;

class Double extends Model
{
    protected $table = 'double';
    
    protected $fillable = ['winner_color', 'winner_num', 'winner_x', 'random_number', 'price', 'price_red', 'price_zero', 'price_black', 'status'];
    
    protected $hidden = ['created_at', 'updated_at'];
    
    public static function game()
    {
        $game = DB::table('double')->orderBy('id', 'desc')->first();
        return $game;
    }
    
}
