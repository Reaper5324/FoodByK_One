<?php

class Customer extends User {
    public int $loyalty_points = 0;

    public function getAddresses(): array{
        return Address::findBy('customer_id', $this->id);

    }

    public function getDefaultAddress(): ?Address{
        $addresses = array_filter($this->getAddresses(), fn(Address $a) => $a->is_default);
        return $addresses ? array_values($addresses)[0] : null;

    }

    public function getCartItems(): array {
        return CartItem::findBy('customer_id', $this->id);

    }

    public function getOrders(): array{
        return Order::findBy('customer_id', $this->id );

    }

    public function addLoyaltyPoints(int $points): bool {
        if ($points < 0 || $this->id === null) {
            return false;
        }

        $this->loyalty_points += $points;
        return $this->save();
    }

    protected function toArray(): array {
        return  array_merge(parent::toArray(), ['loyalty_points' => $this->loyalty_points]);

    }

    protected static function fromRow(array $row): static {
        $c = parent::fromRow($row);
        $c->loyalty_points = (int) ($row['loyalty_points'] ?? 0);
        return $c;
    }

    public static function findCustomerById(int $id): ?static {
        if ($id <= 0) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT u.*, r.role_name FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND r.role_name = ? LIMIT 1'
        );
        $stmt->execute([$id, Role::CUSTOMER]);
        $row = $stmt->fetch();

        return $row ? static::fromRow($row) : null;
    }


    }
