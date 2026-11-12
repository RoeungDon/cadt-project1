<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogActivity
{
    /**
     * Keep a record of every change: who, what, when and from where.
     * Request bodies are not stored, so passwords never reach the log.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $request->user()) {
            ActivityLog::create([
                'user_id'    => $request->user()->id,
                'method'     => $request->method(),
                'path'       => substr($request->path(), 0, 255),
                'status'     => $response->getStatusCode(),
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }
}
