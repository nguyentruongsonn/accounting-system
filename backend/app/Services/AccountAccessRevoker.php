<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountAccessRevoker
{
    public function revoke(User $user): void
    {
        $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
