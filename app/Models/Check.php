<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Check extends Model
{
    public function review() {return $this->belongsTo(Review::class);}
    public function server() {return $this->belongsTo(Server::class);}
}
