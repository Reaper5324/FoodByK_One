<?php

class CsrfMiddleware implements Middleware{


public function handle(Request $request): ?Response{

    if(in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true )){
    return null; //safe methods dont change anything
    }

    $token = $request->header('X-CSRF-Token') ?? $request->input('csrf_token');
    $sesionToken = $_SESSION['csrf_token'] ?? null;

    if(!$token || !$sesionToken || !hash_equals($sesionToken, $token)){
        return Response::error('Invalid or missing CSRF token.', 419);

    
    }

    return null;

}





}