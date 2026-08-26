<?php

class Role extends Model {

    protected static string $table = 'roles';

    // Valid role name constants avoid magic strings across the codebase.
    const CUSTOMER  = 'customer';
    const STAFF = 'staff';
    const ADMIN  = 'admin';

    public function __construct(
        public string $role_name = ''
    ) {}

    public static function customer(): ?static {
        return static::findOneBy('role_name', self::CUSTOMER);
    }

    public static function staff(): ?static {
        return static::findOneBy('role_name', self::STAFF);
    }

    public static function admin(): ?static {
        return static::findOneBy('role_name', self::ADMIN);
    }

    public function getRole(): ?string{
        return $this->role_name;
    }
    protected function toArray(): array {
        return ['role_name' => $this->role_name];
    }

    protected static function fromRow(array $row): static {
        $role            = new static();
        $role->id        = (int) $row['id'];
        $role->role_name = $row['role_name'];
        return $role;
    }

}
