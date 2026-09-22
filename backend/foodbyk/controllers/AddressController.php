<?php

class AddressController extends Controller {

    public function index(Request $request): Response {
        return $this->respond((new AddressService())->listForCustomer($request->user()->id));
    }

    public function create(Request $request): Response {
        return $this->respond((new AddressService())->create($request->user()->id, $request->body), 201);
    }

    public function update(Request $request, array $params): Response {
        $result = (new AddressService())->update((int) $this->param($params, 'id'), $request->user()->id, $request->body);
        return $this->respond($result);
    }

    public function remove(Request $request, array $params): Response {
        $result = (new AddressService())->delete((int) $this->param($params, 'id'), $request->user()->id);
        return $this->respond($result);
    }

    public function setDefault(Request $request, array $params): Response {
        $result = (new AddressService())->setDefault((int) $this->param($params, 'id'), $request->user()->id);
        return $this->respond($result);
    }
}