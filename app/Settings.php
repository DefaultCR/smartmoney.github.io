<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    protected $table = 'settings';
	
	protected $fillable = ['domain', 'sitename', 'title', 'desc', 'keys', 'vk_key', 'vk_secret', 'fake', 'mrh_ID', 'mrh_secret1', 'fk_api', 'fk_wallet', 'min_with_sum', 'bonus', 'p4_rotate'];
    
    protected $hidden = ['created_at', 'updated_at'];
    
}