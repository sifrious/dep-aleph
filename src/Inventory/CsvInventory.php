<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

final readonly class CsvInventory
{
    public function encode(Inventory $inventory): string
    {
        $csv = $this->row(InventoryResource::columns());

        foreach ($inventory->resources as $resource) {
            $csv .= $this->row(array_map($this->field(...), $resource->toArray()));
        }

        return $csv;
    }

    /**
     * @param  array<int|string, string>  $fields
     */
    private function row(array $fields): string
    {
        return implode(',', array_map($this->quote(...), $fields))."\n";
    }

    private function field(string|int|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private function quote(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
