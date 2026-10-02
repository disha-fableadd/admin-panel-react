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
        $users = \App\Models\User::all();
        $notifications = [];
        $now = now();
        
        foreach ($users as $user) {
            $notifications[] = [
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        
        if (!empty($notifications)) {
            \App\Models\Notification::insert($notifications);
        }
    }
}
