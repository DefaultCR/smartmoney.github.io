<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use DB;

class Room2_bets extends Model
{
    protected $table = 'room2_bets';
    
    protected $fillable = ['game_id', 'user_id', 'items', 'sum', 'from', 'to'];
    
    protected $hidden = ['created_at'];
    
}
