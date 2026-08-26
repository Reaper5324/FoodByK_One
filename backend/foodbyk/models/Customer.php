<?php

class Customer extends User {
    public int $loyalty_points = 0;

    public function getAddresses(): array{
        return Address::findBy('customer_id', $this->id);

    }

    public function getDefaultAddress(): ?Address{
        $addresses = array_filter($this->getAddresses(), fn($a) => $a->is_default);
        return $addresses ? array_values($addresses)[0] : null;

    }

    public function getCartItems(): array {
        return CartItem::findBy('customer_id', $this->id);

    }

    public function getOrders(): array{
        return Order::findBy('customer_id', $this->id );

    }

    public function addLoyaltyPoints(int $points): bool {
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
        $user = User::findById($id);
        if($user && $user->isCustomer()){
            return static::fromRow((array) $user);

        }
        return null;
    }


    }
