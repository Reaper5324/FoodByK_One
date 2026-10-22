<?php

class SupportTicketController extends Controller {
    public function customerIndex(Request $request): Response {
        return $this->respond((new SupportTicketService())->customerTickets((int) $request->user()->id));
    }

    public function create(Request $request): Response {
        return $this->respond((new SupportTicketService())->create(
            (int) $request->user()->id,
            $request->input('subject'),
            $request->input('category'),
            $request->input('message'),
            $request->input('order_id'),
            $request->input('product_id')
        ), 201);
    }

    public function adminIndex(Request $request): Response {
        $status = isset($request->query['status']) ? (string) $request->query['status'] : 'all';
        return $this->respond((new SupportTicketService())->adminTickets($status));
    }

    public function adminShow(Request $request, array $params): Response {
        return $this->respond((new SupportTicketService())->adminTicket((int) $this->param($params, 'id')), 200, 404);
    }

    public function reply(Request $request, array $params): Response {
        return $this->respond((new SupportTicketService())->reply((int) $this->param($params, 'id'), (int) $request->user()->id, $request->input('message')));
    }

    public function updateStatus(Request $request, array $params): Response {
        return $this->respond((new SupportTicketService())->setStatus((int) $this->param($params, 'id'), (int) $request->user()->id, $request->input('status')));
    }
}
