<?php

class Request {

    public string $method;
    public string $path;
    public array  $query;
    public array  $body;
    public array  $headers;
    public array  $attributes = []; // middleware-populated - e.g. 'user' => Customer/Staff/Admin

        public static function capture(): self {
        $req = new self();
        $req->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $req->path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $req->query  = $_GET;

        $raw = file_get_contents('php://input');
        $req->body = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            ? (json_decode($raw, true) ?? [])
            : $_POST;

        // HTML forms only submit GET/POST natively - a hidden _method
        // field lets a plain <form> fake PUT/PATCH/DELETE. Harmless for
        // fetch()-based calls, which send the real method and never set this.
        if ($req->method === 'POST' && !empty($req->body['_method'])) {
            $override = strtoupper((string) $req->body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $req->method = $override;
            }
        }

        $req->headers = function_exists('getallheaders') ? getallheaders() : [];

        return $req;
    }

    

    public function input(string $key, mixed $default = null): mixed {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) return $v;
        }
        return null;
    }

    public function user(): ?User {
        return $this->attributes['user'] ?? null;
    }

    
}