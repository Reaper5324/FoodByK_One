<?php

class CustomerPolicyMiddleware implements Middleware {
    public function handle(Request $request): ?Response {
        $user = $request->user();
        if (!$user || (new PolicyService())->hasAcceptedCurrentVersions((int) $user->id)) return null;
        return Response::json([
            'success' => false,
            'data' => ['policy_acceptance_required' => true],
            'error' => 'Please review and accept the current terms and privacy notice to continue.',
        ], 428);
    }
}
