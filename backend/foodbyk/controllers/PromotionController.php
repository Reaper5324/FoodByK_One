<?php

class PromotionController extends Controller {

    public function active(Request $request): Response {
        $active = array_values(array_filter(Promotion::findAll(), fn(Promotion $p) => $p->isActiveAt()));
        return Response::success($active);
    }
}