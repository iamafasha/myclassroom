<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

class ShouldSelectWorkSpace
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        if (!$request->session()->has('workspace_id')) {
             Redirect::setIntendedUrl(
                $request->fullUrl()
            );
            return redirect()->route('space.select');
        }
        setPermissionsTeamId($request->session()->get('workspace_id'));
        return $next($request);
    }
}
