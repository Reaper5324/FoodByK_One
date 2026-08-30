<?php 

class RoleMiddleware implements Middleware{

    private array $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;

    }

    public static function customer(): self {return  new self ([Role::CUSTOMER]);}
    public static function staff(): self { 
        return new self([Role::STAFF]);
    }
    public static function admin(): self{ return new self([Role::ADMIN]);}
    public static function staffOrAdmin(): self{
        return new self([Role::STAFF, Role::ADMIN]);
    }

    public function handle(Request $request): ?Response{

    $user = $request->user();
    if(!$user || !in_array($user->role, $this->allowedRoles, true)){
        return Response::error('You dont have permission to access this resource', 403);


    }
    //if role checks pass
    return null;

    }


    






}