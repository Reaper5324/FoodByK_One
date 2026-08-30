<?php

class HealthController extends Controller {
    public function check(Request $request): Response {
        return Response::success(['status' => 'ok', 'time' => date('c')]);
    }
}