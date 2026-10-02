<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function notifyAllUsers($title, $message)
    {
        $now = now();
        
        \App\Models\Notification::insert([
            'user_id' => null, // null means it's a global notification for all users
            'title' => $title,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
