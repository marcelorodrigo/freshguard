<?php

namespace Database\Factories\FakeProvider;

class CategoriesProvider extends \Faker\Provider\Base
{
    private $names = [
        'Food',
        'Beverages',
        'Electronics',
        'Books',
        'Clothing',
        'Toys',
        'Sports',
        'Home & Kitchen',
        'Beauty & Personal Care',
        'Automotive',
        'Health & Wellness'
    ];

    public function categoryName(): string
    {
        return $this->generator->randomElement($this->names);
    }

}
