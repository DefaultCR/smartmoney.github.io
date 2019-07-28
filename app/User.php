<?php

namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Notifiable;
    
    protected $table = 'users';

    protected $fillable = [
      'id',  'user_id', 'username', 'avatar', 'balance', 'ip', 'is_admin', 'is_moder', 'is_youtuber', 'banchat', 'affiliate_id', 'referred_by', 'ref_money', 'ref_money_history', 'persona_hash', 'unique_id'
    ];
}
