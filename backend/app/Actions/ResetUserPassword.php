<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /** Shared by password reset and invitation account creation. */
    public static function rules(): array
    {
        return ['required', 'string', Password::min(12), 'confirmed'];
    }

    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => self::rules()])->validate();
        DB::transaction(function () use ($user, $input): void {
            $user->forceFill(['password' => $input['password'], 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
