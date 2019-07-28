<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use DB;

class Room1_bets extends Model
{
    protected $table = 'room1_bets';
    
    protected $fillable = ['game_id', 'user_id', 'items', 'sum', 'from', 'to'];
    
    protected $hidden = ['created_at'];
    
}
