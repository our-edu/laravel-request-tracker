<?php

namespace Ouredu\UserLastAccess\Listeners;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Ouredu\UserLastAccess\Models\UserLastAccess;
use Illuminate\Support\Facades\Auth;


class UserLastAccessListener
{
    public function handle($event = null): void
    {
        try {
            $user = Auth::user(); // or use

            if (!$user) {
                Log::warning('UserLastAccessListener: No authenticated user found.');
                return;
            }

            UserLastAccess::updateOrCreate(
                ['user_uuid' => $user->uuid],
                ['last_login_at' => Carbon::now(), 'updated_at' => Carbon::now()]
            );
        } catch (\Exception $exception) {
            Log::error('Cannot update user last access log', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
    }
}