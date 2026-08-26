<?php
// Shared db behaviour for all data models.
//ORM duties
abstract class Model {

    protected static string $table;
    public ?int $id = null; // Primary key.

   

    public static function findById(int $id): ?static {
        $db = Database::getConnection();
        $table = static::$table;
        $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? static::fromRow($row) : null;
    }

    public static function findAll(): array {
        $db = Database::getConnection();
        $table = static::$table;
        $stmt = $db->query("SELECT * FROM `{$table}` ORDER BY id DESC");
        $rows = $stmt->fetchAll();
        //newest first
        return array_map(fn($row) => static::fromRow($row), $rows);

    }

    public static function findBy(string $column, mixed $value): array {

        $db = Database::getConnection();
        $table = static::$table;
        $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = ?");
        $stmt->execute([$value]);
        $rows = $stmt->fetchAll();
        
        return array_map(fn($row) => static::fromRow($row), $rows);
    }

    public static function findOneBy(string $column, mixed $value): ?static {

        $results = static::findBy($column, $value);
        return $results[0] ?? null;
    }

    public function save(): bool {
        if ($this->id === null) {
            return $this->insert();
        } else {
            return $this->update();
        }
        
    }

    public function delete(): bool {

        if ($this->id === null) return false;

        $db = Database::getConnection();
        $table = static::$table;
        $stmt = $db->prepare("DELETE FROM `{$table}` WHERE id = ?");
        return $stmt->execute([$this->id]);
    }


    private function insert(): bool {
        $db     = Database::getConnection();
        $table  = static::$table;
        $fields = $this->toArray();
 
        $cols        = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
 
        $stmt = $db->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})");
        $ok   = $stmt->execute(array_values($fields));
 
        if ($ok) {
            $this->id = (int) $db->lastInsertId();
        }
 
        return $ok;
    }

      private function update(): bool {
        $db     = Database::getConnection();
        $table  = static::$table;
        $fields = $this->toArray();
 
        $setClause = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
        $stmt = $db->prepare("UPDATE `{$table}` SET {$setClause} WHERE id = ?");
 
        return $stmt->execute([...array_values($fields), $this->id]);
    }

     abstract protected function toArray(): array;
     abstract protected static function fromRow(array $row): static;


}
