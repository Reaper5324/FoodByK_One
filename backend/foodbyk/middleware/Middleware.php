<?php



interface Middleware {
    public function handle(Request $request): ?Response;
}