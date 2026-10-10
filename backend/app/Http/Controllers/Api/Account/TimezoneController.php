<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\JsonResponse;

class TimezoneController extends Controller
{
    /** The server's IANA list is the only set PATCH /companies/{company} accepts; browsers' Intl lists differ (aliases, missing zones). */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => DateTimeZone::listIdentifiers()])->header('Cache-Control', 'private, max-age=86400');
    }
}
