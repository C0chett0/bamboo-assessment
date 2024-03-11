<?php

// Spoiler alert .... I LOOOOOVE Collections !!
// Spoiler alert 2 .... By default I favor human readable code over performance

function collect(array $array)
{
    return new Collection($array);
}

class Collection implements ArrayAccess
{
    protected $items;

    public function __construct(array $array)
    {
        $this->items = $array;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset)
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function map(callable $callback): Collection
    {
        return new Collection(array_map($callback, $this->items));
    }

    public function filter(callable $callback): Collection
    {
        return new Collection(array_filter($this->items, $callback));
    }

    public function each(callable $callback): Collection
    {
        array_walk($this->items, $callback);

        return $this;
    }

    public function sum()
    {
        return array_sum($this->items);
    }

    public function keyBy(mixed $property)
    {
        return new Collection(array_reduce($this->items, function ($carry, $item) use ($property) {
            $carry[$item->{$property}] = $item;

            return $carry;
        }, []));
    }

    public function groupBy(mixed $property)
    {
        return new Collection(array_reduce($this->items, function ($carry, $item) use ($property) {
            $carry[$item->{$property}][] = $item;

            return $carry;
        }, []));
    }

    public function pluck(mixed $property)
    {
        return new Collection(array_map(fn ($item) => $item->{$property}, $this->items));
    }

    public function values(): static
    {
        return new Collection(array_values($this->items));
    }

    public function toArray(): array
    {
        return $this->items;
    }
}

class Travel
{
    public $price;

    public $companyId;

    public static function fromArray($array)
    {
        $travel            = new Travel();
        $travel->price     = (float) $array['price'];
        $travel->companyId = $array['companyId'];

        return $travel;
    }
}
class Company implements JsonSerializable
{
    public $id;

    public $createdAt;

    public $name;

    public $parentId;

    public $internalCost;

    public $cost = null;

    public $children = [];

    public static function fromArray($array)
    {
        $company               = new Company();
        $company->id           = $array['id'];
        $company->createdAt    = $array['createdAt'];
        $company->name         = $array['name'];
        $company->parentId     = $array['parentId'];
        $company->internalCost = $array['cost'];

        return $company;
    }

    public function getCost()
    {
        if (is_null($this->cost)) {
            $this->calculateCost();
        }

        return $this->cost;
    }

    public function calculateCost()
    {
        $this->cost = $this->internalCost + collect($this->children)->map(fn ($child) => $child->getCost())->sum();
    }

    public function addChild(Company $company)
    {
        $this->children[] = $company;
    }

    public function isRoot()
    {
        return !$this->parentId;
    }

    public function hasParent()
    {
        return !$this->isRoot();
    }

    public function jsonSerialize()
    {
        return [
            'id'        => $this->id,
            'createdAt' => $this->createdAt,
            'name'      => $this->name,
            'parentId'  => $this->parentId,
            'cost'      => $this->getCost(),
            'children'  => $this->children,
        ];
    }
}
class TestScript
{
    const TRAVEL_ENDPOINT = 'https://5f27781bf5d27e001612e057.mockapi.io/webprovise/travels';

    const COMPANIES_ENDPOINT = 'https://5f27781bf5d27e001612e057.mockapi.io/webprovise/companies';

    public function execute()
    {
        $start = microtime(true);

        // Gather travels data from URL
        $travelData = file_get_contents(static::TRAVEL_ENDPOINT);

        // In the travels we only need the price and the companyId
        // We will sum the price for each company
        $travels = collect(json_decode($travelData, true))
            ->map(fn ($travel) => Travel::fromArray($travel))
            ->groupBy('companyId')
            ->map(function ($travelsOfOneCompany) {
                return collect($travelsOfOneCompany)
                    ->pluck('price')
                    ->sum();
            });

        // Now let's gather the companies data
        $companyData = file_get_contents(static::COMPANIES_ENDPOINT);

        $companies = collect(json_decode($companyData, true))
            // Let's instantiate the companies directly with their internal cost
            ->map(fn ($company) => Company::fromArray(array_merge($company, ['cost' => $travels[$company['id']] ?? 0])))
            ->keyBy('id');

        //Let's create the parent/children relationships only after creating all the companies in case a child appears before its parent in the data
        $companies
            ->filter(fn ($company) => $company->hasParent())
            ->each(function ($company) use ($companies) {
                $companies[$company->parentId]->addChild($company);
            });

        // Now let's filter out only the root companies and calculate the cost
        $results = $companies
            ->filter(fn ($company) => $company->isRoot())
            ->values(); // We don't need the keys anymore

        // Let's save that very very important data in a file
        file_put_contents('CompanyTree.json', json_encode($results->toArray(), JSON_PRETTY_PRINT));

        echo 'Total time: ' . (microtime(true) - $start);
    }
}
(new TestScript())->execute();
