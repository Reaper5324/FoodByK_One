<?php

//Registered Accounts

class User extends Model{

protected static string $table = 'users';


    public function __construct( 
        public string  $full_name = '',
        public string  $email= '',
        public string  $password_hash  = '',
        public string  $role = '',
        public ?string $profile_picture = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $province = null,
        public bool    $is_active = true,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public ?int $role_id = 0
    )
    {
        
    }

    public static function findByEmail(string $email): ?static {
        return static::findOneBy('email' , $email);
    }

    public function verifyPassword(string $password): bool {
        return password_verify($password, $this->password_hash);

    }

    public function setPassword(string $password): void {

    $this->password_hash = password_hash($password, PASSWORD_BCRYPT);

    }



    public function getRole(): ?Role{
        return $this->role_id ? Role::findById($this->role_id) : null;
    }

    public function hasRole(string $rolename): bool {
        $role = $this->getRole();
        return $role?->role_name === $rolename;

    }

    public function isAdmin(): bool{
        return $this->hasRole(Role::ADMIN);

    }
    public function isCustomer(): bool{
        return $this->hasRole(Role::CUSTOMER);
    }
    public function isStaff(): bool{
        return $this->hasRole(Role::STAFF);
    }


    public function deactivate(): bool{
        $this->is_active =false;
        return $this->save();

    }

    public function activate(): bool{
        if ($this-> is_active){
            return true;
        }
        $this->is_active = true;
        return $this->save();

    }

    protected function toArray(): array{

     return [
            'name'=> $this->full_name,
            'email'=> $this->email,
            'password_hash'=> $this->password_hash,
            'role_id'=> $this->role_id,
            'profile_picture' => $this->profile_picture,
            'phone'=> $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'is_active'=> (int) $this->is_active, //1 0
        ];
    }

    #[Override]
    protected static function fromRow(array $row): static
    {
        $user= new static();
        $user->id  = (int)  $row['id'];
        $user->full_name =  $row['name'];
        $user->email =  $row['email'];
        $user->password_hash = $row['password_hash'];
        $user->role_id = (int)$row['role_id'];
        $user->profile_picture =$row['profile_picture'] ?? null;
        $user->phone  =$row['phone'] ?? null;
        $user->address = $row['address'] ?? null;
        $user->city = $row['city'] ?? null;
        $user->province = $row['province'] ?? null;
        $user->is_active = (bool) $row['is_active'];
        $user->created_at = $row['created_at'] ?? null;
        $user->updated_at = $row['updated_at'] ?? null;
        return $user;
    }





}







?>
